<?php

/**
 * @file
 * Event Repeater module.
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Implements hook_entity_insert().
 */
function event_repeater_entity_insert(EntityInterface $entity) {
  if ($entity->getEntityTypeId() == 'node' && $entity->getType() == 'event') {
    _event_repeater_generate_repeats($entity);
  }
}

/**
 * Implements hook_entity_update().
 */
function event_repeater_entity_update(EntityInterface $entity) {
  if ($entity->getEntityTypeId() == 'node' && $entity->getType() == 'event') {
    // Delete existing repeats and regenerate
    _event_repeater_delete_existing_repeats($entity);
    _event_repeater_generate_repeats($entity);
  }
}

/**
 * Implements hook_entity_delete().
 */
function event_repeater_entity_delete(EntityInterface $entity) {
  if ($entity->getEntityTypeId() == 'node' && $entity->getType() == 'event') {
    _event_repeater_delete_existing_repeats($entity);
  }
}

/**
 * Generate repeat events.
 */
function _event_repeater_generate_repeats(EntityInterface $entity) {
  // Check if repeat is enabled
  if (!$entity->hasField('field_repeat_enabled') || !$entity->get('field_repeat_enabled')->value) {
    return;
  }

  $repeat_type = $entity->get('field_repeat_type')->value;
  $repeat_interval = (int) $entity->get('field_repeat_interval')->value;
  $start_date = $entity->get('field_start_date')->value;
  $end_date = $entity->get('field_end_date')->value;
  
  if (!$repeat_type || !$repeat_interval || !$start_date) {
    return;
  }

  $start_datetime = new DrupalDateTime($start_date);
  $end_datetime = new DrupalDateTime($end_date);
  $event_duration = $end_datetime->getTimestamp() - $start_datetime->getTimestamp();

  // Determine when to stop generating repeats
  $repeat_end_date = NULL;
  $repeat_count = NULL;
  
  if ($entity->hasField('field_repeat_end_date') && !$entity->get('field_repeat_end_date')->isEmpty()) {
    $repeat_end_date = new DrupalDateTime($entity->get('field_repeat_end_date')->value);
  }
  
  if ($entity->hasField('field_repeat_count') && !$entity->get('field_repeat_count')->isEmpty()) {
    $repeat_count = (int) $entity->get('field_repeat_count')->value;
  }

  $generated_count = 0;
  $max_repeats = 100; // Safety limit
  $current_date = clone $start_datetime;

  while ($generated_count < $max_repeats) {
    // Calculate next occurrence
    switch ($repeat_type) {
      case 'daily':
        $current_date->modify("+{$repeat_interval} days");
        break;
      case 'weekly':
        $current_date->modify("+{$repeat_interval} weeks");
        break;
      case 'monthly':
        $current_date->modify("+{$repeat_interval} months");
        break;
      case 'yearly':
        $current_date->modify("+{$repeat_interval} years");
        break;
      default:
        return;
    }

    // Check if we should stop generating
    if ($repeat_end_date && $current_date > $repeat_end_date) {
      break;
    }
    
    if ($repeat_count && $generated_count >= $repeat_count) {
      break;
    }

    // Create new event node
    $new_end_date = clone $current_date;
    $new_end_date->setTimestamp($current_date->getTimestamp() + $event_duration);

    $repeat_node = Node::create([
      'type' => 'event',
      'title' => $entity->getTitle() . ' (' . $current_date->format('M j, Y') . ')',
      'field_start_date' => $current_date->format('Y-m-d\TH:i:s'),
      'field_end_date' => $new_end_date->format('Y-m-d\TH:i:s'),
      'status' => $entity->isPublished(),
      'uid' => $entity->getOwnerId(),
    ]);

    // Copy other fields (except repeat fields)
    foreach ($entity->getFields() as $field_name => $field) {
      if (strpos($field_name, 'field_repeat_') !== 0 && 
          !in_array($field_name, ['nid', 'vid', 'title', 'field_start_date', 'field_end_date', 'created', 'changed'])) {
        if ($entity->hasField($field_name) && !$entity->get($field_name)->isEmpty()) {
          $repeat_node->set($field_name, $entity->get($field_name)->getValue());
        }
      }
    }

    // Add reference to parent event
    if ($repeat_node->hasField('field_parent_event')) {
      $repeat_node->set('field_parent_event', $entity->id());
    }

    $repeat_node->save();
    $generated_count++;
  }
}

/**
 * Delete existing repeat events.
 */
function _event_repeater_delete_existing_repeats(EntityInterface $entity) {
  if (!$entity->hasField('field_parent_event')) {
    return;
  }

  $query = \Drupal::entityQuery('node')
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