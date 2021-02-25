<?php

namespace Drupal\node_duplicate\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Confirmation for the "Duplicate" entity operation.
 */
class NodeDuplicateConfirmForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    /** @var \Drupal\node\Entity\Node $node */
    $node = \Drupal::request()->get('node');
    return $this->t('Are you sure you want to duplicate the @bundle %label?', array(
      '@bundle' => node_get_type_label($node),
      '%label' => $node->label(),
    ));
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->getQuestion();
  }

  public function getRedirectUrl() {
    $query = \Drupal::request()->query;
    if ($query->has('destination')) {
      $options = UrlHelper::parse($query->get('destination'));
      return Url::fromUri('internal:/' . $options['path'], $options);
     } else {
      return Url::fromRoute('system.admin_content');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    $query = \Drupal::request()->query;
    if ($query->has('destination')) {
      $options = UrlHelper::parse($query->get('destination'));
      return Url::fromUri('internal:/' . $options['path'], $options);
    }
    else {
      return Url::fromRoute('system.admin_content');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'node_duplicate_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\node_duplicate\Plugin\Action\DuplicateNodeAction $action */
    $action = \Drupal::entityTypeManager()
      ->getStorage('action')
      ->load('node_duplicate_action')
      ->getPlugin();
    /** @var \Drupal\node\Entity\Node $node */
    $node = \Drupal::request()->get('node');
    $duplicated_node = $action->execute($node);
    \Drupal::messenger()->addStatus($this->t('@bundle <a href="@url" target="_blank">@label</a> has been duplicated.', [
      '@bundle' => node_get_type_label($duplicated_node),
      '@url' => $duplicated_node->toUrl('edit-form')->toString(),
      '@label' => $duplicated_node->label(),
    ]));
    $form_state->setRedirectUrl($this->getRedirectUrl());
  }

}
