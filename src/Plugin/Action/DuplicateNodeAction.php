<?php

namespace Drupal\node_duplicate\Plugin\Action;


use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\filter\Render\FilteredMarkup;

/**
 * Duplicates a node.
 *
 * @Action(
 *   id = "node_duplicate_action",
 *   label = @Translation("Duplicate node"),
 *   type = "node"
 * )
 */
class DuplicateNodeAction extends ActionBase {

  /**
   * Duplicates a node.
   *
   * @param \Drupal\node\NodeInterface $entity
   *   Node to duplicate.
   *
   * @return \Drupal\node\NodeInterface
   *   Duplicated node.
   */
  public function execute($entity = NULL) {
    $duplicated_entity = $entity->createDuplicate();

    // Add the "Clone of " prefix to the entity label.
    if ($duplicated_entity instanceof TranslatableInterface) {
      if ($label_key = $duplicated_entity->getEntityType()->getKey('label')) {
        foreach ($duplicated_entity->getTranslationLanguages() as $language) {
          $langcode = $language->getId();
          $duplicated_entity = $duplicated_entity->getTranslation($langcode);
          $new_label = $this->t('Clone of @label', [
            '@label' => FilteredMarkup::create($duplicated_entity->label()),
          ], [
            'langcode' => $langcode,
          ]);
          $duplicated_entity->set($label_key, $new_label);
        }
      }
    }

    $duplicated_entity->status = \Drupal\node\NodeInterface::NOT_PUBLISHED;
    $duplicated_entity->save();
    return $duplicated_entity;
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {
    /** @var \Drupal\node\NodeInterface $object */
    return $object->access('update', $account, $return_as_object);
  }
}
