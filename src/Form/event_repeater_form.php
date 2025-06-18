<?php

/**
 * Implements hook_form_alter().
 */
function event_repeater_form_alter(&$form, $form_state, $form_id) {
  if ($form_id == 'node_event_form' || $form_id == 'node_event_edit_form') {
    // Add states API for conditional fields
    $form['field_repeat_type']['#states'] = [
      'visible' => [
        ':input[name="field_repeat_enabled[value]"]' => ['checked' => TRUE],
      ],
    ];
    
    $form['field_repeat_interval']['#states'] = [
      'visible' => [
        ':input[name="field_repeat_enabled[value]"]' => ['checked' => TRUE],
      ],
    ];
    
    $form['field_repeat_end_date']['#states'] = [
      'visible' => [
        ':input[name="field_repeat_enabled[value]"]' => ['checked' => TRUE],
      ],
    ];
    
    $form['field_repeat_count']['#states'] = [
      'visible' => [
        ':input[name="field_repeat_enabled[value]"]' => ['checked' => TRUE],
      ],
    ];

    // Add validation
    $form['#validate'][] = 'event_repeater_form_validate';
  }
}

/**
 * Custom validation for event repeat form.
 */
function event_repeater_form_validate($form, $form_state) {
  $repeat_enabled = $form_state->getValue(['field_repeat_enabled', 0, 'value']);
  
  if ($repeat_enabled) {
    $repeat_type = $form_state->getValue(['field_repeat_type', 0, 'value']);
    $repeat_interval = $form_state->getValue(['field_repeat_interval', 0, 'value']);
    $repeat_end_date = $form_state->getValue(['field_repeat_end_date', 0, 'value']);
    $repeat_count = $form_state->getValue(['field_repeat_count', 0, 'value']);
    
    if (empty($repeat_type)) {
      $form_state->setErrorByName('field_repeat_type', t('Repeat type is required when repeat is enabled.'));
    }
    
    if (empty($repeat_interval) || $repeat_interval < 1) {
      $form_state->setErrorByName('field_repeat_interval', t('Repeat interval must be at least 1.'));
    }
    
    if (empty($repeat_end_date) && empty($repeat_count)) {
      $form_state->setErrorByName('field_repeat_end_date', t('Either repeat end date or repeat count must be specified.'));
    }
  }
}