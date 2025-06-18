<?php

namespace Drupal\event_repeater\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\node\Entity\Node;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * Secure service for handling event repetition.
 */
class SecureEventRepeaterService {

  protected $entityTypeManager;
  protected $currentUser;
  protected $logger;
  protected $config;
  protected $database;

  // Security limits
  const MAX_REPEAT_COUNT = 100;
  const MAX_REPEAT_INTERVAL = 365;
  const MAX_YEARS_IN_FUTURE = 5;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $current_user,
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    Connection $database
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->currentUser = $current_user;
    $this->logger = $logger_factory->get('event_repeater');
    $this->config = $config_factory->get('event_repeater.settings');
    $this->database = $database;
  }

  /**
   * Generate repeat events with security checks.
   */
  public function generateRepeats(EntityInterface $entity) {
    // Security check: Verify permissions
    if (!$this->hasPermissionToCreateRepeats($entity)) {
      $this->logger->warning('User @uid attempted to create repeats without permission for node @nid', [
        '@uid' => $this->currentUser->id(),
        '@nid' => $entity->id(),
      ]);
      return FALSE;
    }

    // Validation checks
    $validation_result = $this->validateRepeatParameters($entity);
    if ($validation_result !== TRUE) {
      $this->logger->error('Invalid repeat parameters: @error', ['@error' => $validation_result]);
      return FALSE;
    }

    // Use database transaction for integrity
    $transaction = $this->database->startTransaction();
    
    try {
      $this->deleteExistingRepeats($entity);
      $config = $this->extractAndValidateRepeatConfig($entity);
      
      if (!$config) {
        return FALSE;
      }

      // Check if we should use batch processing
      $estimated_repeats = $this->estimateRepeatCount($config);
      if ($estimated_repeats > 50) {
        $this->scheduleBatchGeneration($entity, $config);
        return TRUE;
      }

      $this->createRepeatsSecurely($entity, $config);
      
    } catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Failed to generate repeats: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Check permissions for creating repeat events.
   */
  protected function hasPermissionToCreateRepeats(EntityInterface $entity) {
    // Check basic content creation permission
    if (!$this->currentUser->hasPermission('create event content')) {
      return FALSE;
    }

    // Check if user can edit this specific entity
    $access = $entity->access('update', $this->currentUser, TRUE);
    if (!$access->isAllowed()) {
      return FALSE;
    }

    // Custom permission check
    if (!$this->currentUser->hasPermission('create recurring events')) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Validate repeat parameters for security.
   */
  protected function validateRepeatParameters(EntityInterface $entity) {
    if (!$entity->hasField('field_repeat_enabled') || !$entity->get('field_repeat_enabled')->value) {
      return 'Repeat not enabled';
    }

    // Validate repeat type
    $repeat_type = $entity->get('field_repeat_type')->value;
    $allowed_types = ['daily', 'weekly', 'monthly', 'yearly'];
    if (!in_array($repeat_type, $allowed_types)) {
      return 'Invalid repeat type';
    }

    // Validate interval
    $interval = (int) $entity->get('field_repeat_interval')->value;
    if ($interval < 1 || $interval > self::MAX_REPEAT_INTERVAL) {
      return 'Invalid repeat interval';
    }

    // Validate dates
    $start_date = $entity->get('field_start_date')->value;
    $end_date = $entity->get('field_end_date')->value;
    
    if (empty($start_date) || empty($end_date)) {
      return 'Missing required dates';
    }

    try {
      $start_datetime = new DrupalDateTime($start_date);
      $end_datetime = new DrupalDateTime($end_date);
      
      // Check for reasonable date range
      if ($start_datetime > $end_datetime) {
        return 'Start date must be before end date';
      }

      // Prevent dates too far in the future
      $max_future = new DrupalDateTime();
      $max_future->modify('+' . self::MAX_YEARS_IN_FUTURE . ' years');
      
      if ($start_datetime > $max_future) {
        return 'Start date too far in future';
      }

    } catch (\Exception $e) {
      return 'Invalid date format';
    }

    // Validate repeat count/end date
    $repeat_count = $entity->get('field_repeat_count')->value;
    $repeat_end_date = $entity->get('field_repeat_end_date')->value;

    if (empty($repeat_count) && empty($repeat_end_date)) {
      return 'Must specify either repeat count or end date';
    }

    if (!empty($repeat_count) && ($repeat_count < 1 || $repeat_count > self::MAX_REPEAT_COUNT)) {
      return 'Invalid repeat count';
    }

    return TRUE;
  }

  /**
   * Extract and validate repeat configuration.
   */
  protected function extractAndValidateRepeatConfig(EntityInterface $entity) {
    $start_date = $entity->get('field_start_date')->value;
    $end_date = $entity->get('field_end_date')->value;
    
    try {
      $start_datetime = new DrupalDateTime($start_date);
      $end_datetime = new DrupalDateTime($end_date);
    } catch (\Exception $e) {
      $this->logger->error('Date parsing error: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }

    // Sanitize and validate inputs
    $config = [
      'start_date' => $start_datetime,
      'end_date' => $end_datetime,
      'duration' => $end_datetime->getTimestamp() - $start_datetime->getTimestamp(),
      'repeat_type' => $this->sanitizeString($entity->get('field_repeat_type')->value),
      'interval' => max(1, min(self::MAX_REPEAT_INTERVAL, (int) $entity->get('field_repeat_interval')->value)),
      'repeat_end_date' => NULL,
      'repeat_count' => NULL,
    ];

    // Validate duration isn't negative
    if ($config['duration'] < 0) {
      return FALSE;
    }

    // Handle optional fields safely
    if ($entity->hasField('field_repeat_end_date') && !$entity->get('field_repeat_end_date')->isEmpty()) {
      try {
        $config['repeat_end_date'] = new DrupalDateTime($entity->get('field_repeat_end_date')->value);
      } catch (\Exception $e) {
        $this->logger->error('Repeat end date parsing error: @message', ['@message' => $e->getMessage()]);
      }
    }

    if ($entity->hasField('field_repeat_count') && !$entity->get('field_repeat_count')->isEmpty()) {
      $config['repeat_count'] = max(1, min(self::MAX_REPEAT_COUNT, (int) $entity->get('field_repeat_count')->value));
    }

    return $config;
  }

  /**
   * Create repeat events with security measures.
   */
  protected function createRepeatsSecurely(EntityInterface $original, array $config) {
    $dates = $this->calculateRepeatDates($config);
    $created_count = 0;
    
    foreach ($dates as $date_info) {
      try {
        // Additional security check before each creation
        if (!$this->currentUser->hasPermission('create event content')) {
          break;
        }

        $repeat_node = $this->createSecureRepeatEvent($original, $date_info);
        if ($repeat_node) {
          $created_count++;
        }

        // Prevent memory exhaustion
        if ($created_count % 10 == 0) {
          drupal_flush_all_caches();
        }

      } catch (\Exception $e) {
        $this->logger->error('Failed to create repeat event: @message', ['@message' => $e->getMessage()]);
        // Continue with other events rather than failing completely
        continue;
      }
    }

    $this->logger->info('Created @count repeat events for node @nid', [
      '@count' => $created_count,
      '@nid' => $original->id(),
    ]);
  }

  /**
   * Create a single repeat event with security checks.
   */
  protected function createSecureRepeatEvent(EntityInterface $original, array $date_info) {
    // Validate title length to prevent XSS
    $title = $this->sanitizeTitle($original->getTitle() . ' (' . $date_info['start']->format('M j, Y') . ')');
    
    $repeat_node = Node::create([
      'type' => 'event',
      'title' => $title,
      'field_start_date' => $date_info['start']->format('Y-m-d\TH:i:s'),
      'field_end_date' => $date_info['end']->format('Y-m-d\TH:i:s'),
      'status' => $original->isPublished(),
      'uid' => $original->getOwnerId(),
    ]);

    // Securely copy fields
    $this->copyFieldsSecurely($original, $repeat_node);

    // Set parent reference
    if ($repeat_node->hasField('field_parent_event')) {
      $repeat_node->set('field_parent_event', $original->id());
    }

    // Validate before saving
    $violations = $repeat_node->validate();
    if ($violations->count() > 0) {
      $this->logger->error('Validation errors creating repeat event: @errors', [
        '@errors' => (string) $violations,
      ]);
      return NULL;
    }

    $repeat_node->save();
    return $repeat_node;
  }

  /**
   * Safely copy fields with validation.
   */
  protected function copyFieldsSecurely(EntityInterface $original, EntityInterface $repeat) {
    $skip_fields = [
      'nid', 'vid', 'title', 'field_start_date', 'field_end_date', 
      'created', 'changed', 'field_repeat_enabled', 'field_repeat_type',
      'field_repeat_interval', 'field_repeat_end_date', 'field_repeat_count'
    ];

    foreach ($original->getFields() as $field_name => $field) {
      if (in_array($field_name, $skip_fields) || $original->get($field_name)->isEmpty()) {
        continue;
      }

      if (!$repeat->hasField($field_name)) {
        continue;
      }

      // Check field access
      $field_access = $original->get($field_name)->access('view', $this->currentUser, TRUE);
      if (!$field_access->isAllowed()) {
        continue;
      }

      try {
        // Sanitize field values
        $value = $this->sanitizeFieldValue($original->get($field_name)->getValue(), $field_name);
        $repeat->set($field_name, $value);
      } catch (\Exception $e) {
        $this->logger->warning('Failed to copy field @field: @message', [
          '@field' => $field_name,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Sanitize field values based on field type.
   */
  protected function sanitizeFieldValue($value, $field_name) {
    // Add field-type specific sanitization
    // This is a simplified example - expand based on your field types
    if (is_string($value)) {
      return filter_xss($value);
    }
    
    return $value;
  }

  /**
   * Sanitize title to prevent XSS.
   */
  protected function sanitizeTitle($title) {
    return substr(filter_xss($title, []), 0, 255);
  }

  /**
   * Sanitize string input.
   */
  protected function sanitizeString($input) {
    return filter_xss(trim($input), []);
  }

  /**
   * Delete existing repeats with proper access checks.
   */
  public function deleteExistingRepeats(EntityInterface $entity) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'event')
      ->condition('field_parent_event', $entity->id())
      ->accessCheck(TRUE); // Proper access checking

    $nids = $query->execute();

    if (!empty($nids)) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        // Check delete permission for each node
        if ($node->access('delete', $this->currentUser)) {
          try {
            $node->delete();
          } catch (\Exception $e) {
            $this->logger->error('Failed to delete repeat event @nid: @message', [
              '@nid' => $node->id(),
              '@message' => $e->getMessage(),
            ]);
          }
        }
      }
    }
  }
}
