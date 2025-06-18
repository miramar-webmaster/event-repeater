<?php

namespace Drupal\event_repeater\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\Entity\Node;

/**
 * Service for handling event repetition.
 */
class EventRepeaterService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs an EventRepeaterService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * Generate repeat events based on recurrence rules.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The original event entity.
   */
  public function generateRepeats(EntityInterface $entity) {
    if (!$this->shouldGenerateRepeats($entity)) {
      return;
    }

    $this->deleteExistingRepeats($entity);
    
    $config = $this->extractRepeatConfig($entity);
    if (!$config) {
      return;
    }

    $dates = $this->calculateRepeatDates($config);
    
    foreach ($dates as $date_info) {
      $this->createRepeatEvent($entity, $date_info);
    }
  }

  /**
   * Check if repeats should be generated.
   */
  protected function shouldGenerateRepeats(EntityInterface $entity) {
    return $entity->getEntityTypeId() == 'node' 
      && $entity->getType() == 'event'
      && $entity->hasField('field_repeat_enabled')
      && $entity->get('field_repeat_enabled')->value;
  }

  /**
   * Extract repeat configuration from entity.
   */
  protected function extractRepeatConfig(EntityInterface $entity) {
    $start_date = $entity->get('field_start_date')->value;
    $end_date = $entity->get('field_end_date')->value;
    $repeat_type = $entity->get('field_repeat_type')->value;
    $repeat_interval = (int) $entity->get('field_repeat_interval')->value;

    if (!$start_date || !$end_date || !$repeat_type || !$repeat_interval) {
      return FALSE;
    }

    $start_datetime = new DrupalDateTime($start_date);
    $end_datetime = new DrupalDateTime($end_date);

    $config = [
      'start_date' => $start_datetime,
      'end_date' => $end_datetime,
      'duration' => $end_datetime->getTimestamp() - $start_datetime->getTimestamp(),
      'repeat_type' => $repeat_type,
      'interval' => $repeat_interval,
      'repeat_end_date' => NULL,
      'repeat_count' => NULL,
    ];

    if ($entity->hasField('field_repeat_end_date') && !$entity->get('field_repeat_end_date')->isEmpty()) {
      $config['repeat_end_date'] = new DrupalDateTime($entity->get('field_repeat_end_date')->value);
    }

    if ($entity->hasField('field_repeat_count') && !$entity->get('field_repeat_count')->isEmpty()) {
      $config['repeat_count'] = (int) $entity->get('field_repeat_count')->value;
    }

    return $config;
  }

  /**
   * Calculate all repeat dates.
   */
  protected function calculateRepeatDates(array $config) {
    $dates = [];
    $current_date = clone $config['start_date'];
    $generated_count = 0;
    $max_repeats = 100; // Safety limit

    while ($generated_count < $max_repeats) {
      // Calculate next occurrence
      $this->advanceDate($current_date, $config['repeat_type'], $config['interval']);

      // Check stopping conditions
      if ($config['repeat_end_date'] && $current_date > $config['repeat_end_date']) {
        break;
      }

      if ($config['repeat_count'] && $generated_count >= $config['repeat_count']) {
        break;
      }

      $end_date = clone $current_date;
      $end_date->setTimestamp($current_date->getTimestamp() + $config['duration']);

      $dates[] = [
        'start' => clone $current_date,
        'end' => $end_date,
      ];

      $generated_count++;
    }

    return $dates;
  }

  /**
   * Advance date based on repeat type and interval.
   */
  protected function advanceDate(DrupalDateTime $date, $type, $interval) {
    switch ($type) {
      case 'daily':
        $date->modify("+{$interval} days");
        break;
      case 'weekly':
        $date->modify("+{$interval} weeks");
        break;
      case 'monthly':
        $date->modify("+{$interval} months");
        break;
      case 'yearly':
        $date->modify("+{$interval} years");
        break;
    }
  }

  /**
   * Create a repeat event node.
   */
  protected function createRepeatEvent(EntityInterface $original, array $date_info) {
    $repeat_node = Node::create([
      'type' => 'event',
      'title' => $original->getTitle() . ' (' . $date_info['start']->format('M j, Y') . ')',
      'field_start_date' => $date_info['start']->format('Y-m-d\TH:i:s'),
      'field_end_date' => $date_info['end']->format('Y-m-d\TH:i:s'),
      'status' => $original->isPublished(),
      'uid' => $original->getOwnerId(),
    ]);

    // Copy other fields
    $this->copyFields($original, $repeat_node);

    // Set parent reference
    if ($repeat_node->hasField('field_parent_event')) {
      $repeat_node->set('field_parent_event', $original->id());
    }

    $repeat_node->save();
  }

  /**
   * Copy fields from original to repeat event.
   */
  protected function copyFields(EntityInterface $original, EntityInterface $repeat) {
    $skip_fields = [
      'nid', 'vid', 'title', 'field_start_date', 'field_end_date', 
      'created', 'changed', 'field_repeat_enabled', 'field_repeat_type',
      'field_repeat_interval', 'field_repeat_end_date', 'field_repeat_count'
    ];

    foreach ($original->getFields() as $field_name => $field) {
      if (!in_array($field_name, $skip_fields) && 
          !$original->get($field_name)->isEmpty() &&
          $repeat->hasField($field_name)) {
        $repeat->set($field_name, $original->get($field_name)->getValue());
      }
    }
  }

  /**
   * Delete existing repeat events.
   */
  public function deleteExistingRepeats(EntityInterface $entity) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'event')
      ->condition('field_parent_event', $entity->id())
      ->accessCheck(FALSE);

    $nids = $query->execute();

    if (!empty($nids)) {
      $nodes = Node::loadMultiple($nids);
      foreach ($nodes as $node) {
        $node->delete();
      }
    }
  }
}