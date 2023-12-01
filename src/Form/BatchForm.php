<?php

namespace Drupal\batch_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

class BatchForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['custom_batch_process.setting'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return "custom_batch_process_settings";
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('custom_batch_process.setting');

    // Load all content types to make options.
    $content_types = \Drupal::service('entity_type.manager')->getStorage('node_type')->loadMultiple();
    $options = [];
    foreach ($content_types as $content_type) {
      if ($content_type->status()) {
        $options[$content_type->id()] = $content_type->label();
      }
    }

    $form['content-type'] = [
      '#type' => 'select',
      '#title' => "Delete this content",
      '#options' => $options,
      '#default_value' => $config->get("content-type"),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('custom_batch_process.setting');
    $config->set("content-type", $form_state->getValue('content-type'));
    $config->save();

    // Load all node ids for the selected content type.
    $nids = \Drupal::entityQuery('node')->condition('type',$config->get("content-type"))->accessCheck(TRUE)->execute();

    // Node Objects.
    $nodes = Node::loadMultiple($nids);

    if (empty($nodes)) {
      \Drupal::messenger()->addMessage("There is no content with this content type");
      return;
    }

    // Construct operations for each node. Each node will act as one batch item.
    foreach ($nodes as $node) {
      // Pass node as an argument for batch process.
      $operations[] = [
        '\Drupal\batch_api\Form\BatchForm::deleteNode',
        [$node]
      ];
    }

    // Set batch process.
    $batch = [
      'title' => t('Deleting Nodes'),
      'operations' => $operations,
      'finished' => '\Drupal\batch_api\Form\BatchForm::finishUpdates',
    ];
    batch_set($batch);
  }

  public static function deleteNode($node, &$context) {
    try {
      // Delete the node.
      $node->delete();
      // Track the deletion as a successful operation.
      $context['results']['success'][] = 1;
    } catch (\Exception $e) {
      // Track any failed deletions.
      $context['results']['failed'][] = 1;
    }
  }

  public static function finishUpdates($success, $results, $operations) {
    $message = "";
    // If batch is success.
    if ($success) {
      // Construct a message to show once finished.
      // formatPlural() will help you to print the message in singular or plural form based on count.
      if (isset($results['success']) && !empty(($results['success']))) {
        // If you have 5 items success, then the array will be $results['success'] = [1,1,1,1,1].
        $successcount = count($results['success']);
        $message .= \Drupal::translation()->formatPlural(
          $successcount,
          'One record success.', '@count records success. '
        );
      }
      if (isset($results['failed']) && !empty(($results['failed']))) {
        $failedcount = count($results['failed']);
        $message .= \Drupal::translation()->formatPlural(
          $failedcount,
          'One record failed.', '@count records failed. '
        );
      }
    } else {
      // If batch process fails.
      $message = t('Finished with an error.');
    }
    \Drupal::messenger()->addMessage($message);
  }

}
