<?php

/**
 * @package       Joomla.Administrator
 * @subpackage    com_guidedtours
 *
 * @copyright     (C) 2023 Open Source Matters, Inc. <https://www.joomla.org>
 * @license       GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Joomla\Component\Guidedtours\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Object\CMSObject;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\ParameterType;
use Joomla\Utilities\ArrayHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Model class for tour
 *
 * @since  4.3.0
 */
class TourModel extends AdminModel
{
    /**
     * The prefix to use with controller messages.
     *
     * @var   string
     * @since 4.3.0
     */
    protected $text_prefix = 'COM_GUIDEDTOURS';

    /**
     * Type alias for content type
     *
     * @var string
     * @since 4.3.0
     */
    public $typeAlias = 'com_guidedtours.tour';

    /**
     * Method to save the form data.
     *
     * @param   array  $data  The form data.
     *
     * @return  boolean True on success.
     *
     * @since  4.3.0
     */
    public function save($data)
    {
        $input = Factory::getApplication()->input;

        // Language keys must include GUIDEDTOUR to prevent save issues
        if (strpos($data['description'], 'GUIDEDTOUR') !== false) {
            $data['description'] = strip_tags($data['description']);
        }

        if ($input->get('task') == 'save2copy') {
            $origTable = clone $this->getTable();
            $origTable->load($input->getInt('id'));

            $data['published'] = 0;
        }

        // Set step language to parent tour language on save.
        $id   = $data['id'];
        $lang = $data['language'];

        $this->setStepsLanguage($id, $lang);

        $result = parent::save($data);

        // Create default step for new tour
        if ($result && $input->getCmd('task') !== 'save2copy' && $this->getState($this->getName() . '.new')) {
            $tourId = (int) $this->getState($this->getName() . '.id');

            $table = $this->getTable('Step');

            $table->id          = 0;
            $table->title       = 'COM_GUIDEDTOURS_BASIC_STEP';
            $table->description = '';
            $table->tour_id     = $tourId;
            $table->published   = 1;

            $table->store();
        }

        return $result;
    }

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param   \Joomla\CMS\Table\Table  $table  The Table object
     *
     * @return  void
     *
     * @since  4.3.0
     */
    protected function prepareTable($table)
    {
        $date = Factory::getDate()->toSql();

        $table->title = htmlspecialchars_decode($table->title, ENT_QUOTES);

        if (empty($table->id)) {
            // Set the values
            $table->created = $date;

            // Set ordering to the last item if not set
            if (empty($table->ordering)) {
                $db    = $this->getDatabase();
                $query = $db->getQuery(true)
                    ->select('MAX(ordering)')
                    ->from($db->quoteName('#__guidedtours'));
                $db->setQuery($query);
                $max = $db->loadResult();

                $table->ordering = $max + 1;
            }
        } else {
            // Set the values
            $table->modified    = $date;
            $table->modified_by = $this->getCurrentUser()->id;
        }
    }

    /**
     * Abstract method for getting the form from the model.
     *
     * @param   array    $data      Data for the form.
     * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
     *
     * @return \JForm|boolean  A JForm object on success, false on failure
     *
     * @since  4.3.0
     */
    public function getForm($data = [], $loadData = true)
    {
        // Get the form.
        $form = $this->loadForm(
            'com_guidedtours.tour',
            'tour',
            [
                'control'   => 'jform',
                'load_data' => $loadData,
            ]
        );

        if (empty($form)) {
            return false;
        }

        $id = $data['id'] ?? $form->getValue('id');

        $item = $this->getItem($id);

        // Modify the form based on access controls.
        if (!$this->canEditState((object) $item)) {
            $form->setFieldAttribute('published', 'disabled', 'true');
            $form->setFieldAttribute('published', 'required', 'false');
            $form->setFieldAttribute('published', 'filter', 'unset');
        }

        $currentDate = Factory::getDate()->toSql();

        $form->setFieldAttribute('created', 'default', $currentDate);
        $form->setFieldAttribute('modified', 'default', $currentDate);

        return $form;
    }

    /**
     * Method to get the data that should be injected in the form.
     *
     * @return mixed  The data for the form.
     *
     * @since  4.3.0
     */
    protected function loadFormData()
    {
        // Check the session for previously entered form data.
        $data = Factory::getApplication()->getUserState(
            'com_guidedtours.edit.tour.data',
            []
        );

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
    }

    /**
     * Method to test whether a record can be deleted.
     *
     * @param   object  $record  A record object.
     *
     * @return  boolean  True if allowed to delete the record. Defaults to the permission for the component.
     *
     * @since  4.3.0
     */
    protected function canDelete($record)
    {
        if (!empty($record->id)) {
            return $this->getCurrentUser()->authorise('core.delete', 'com_guidedtours.tour.' . (int) $record->id);
        }

        return false;
    }

    /**
     * Method to test whether a record can have its state changed.
     *
     * @param   object  $record  A record object.
     *
     * @return  boolean  True if allowed to change the state of the record.
     * Defaults to the permission set in the component.
     *
     * @since   4.3.0
     */
    protected function canEditState($record)
    {
        // Check for existing tour.
        if (!empty($record->id)) {
            return $this->getCurrentUser()->authorise('core.edit.state', 'com_guidedtours.tour.' . (int) $record->id);
        }

        // Default to component settings if neither tour nor category known.
        return parent::canEditState($record);
    }

    /**
     * Method to get a single record.
     *
     * @param   integer  $pk  The id of the primary key.
     *
     * @return  CMSObject|boolean  Object on success, false on failure.
     *
     * @since   4.3.0
     */
    public function getItem($pk = null)
    {
        Factory::getLanguage()->load('com_guidedtours.sys', JPATH_ADMINISTRATOR);

        $result = parent::getItem($pk);

        if (!empty($result->id)) {
            $result->title_translation       = Text::_($result->title);
            $result->description_translation = Text::_($result->description);
        }

        return $result;
    }

    /**
     * Delete all steps if a tour is deleted
     *
     * @param   object  $pks  The primary key related to the tours.
     *
     * @return  boolean
     *
     * @since   4.3.0
     */
    public function delete(&$pks)
    {
        $pks   = ArrayHelper::toInteger((array) $pks);
        $table = $this->getTable();

        // Include the plugins for the delete events.
        PluginHelper::importPlugin($this->events_map['delete']);

        // Iterate the items to delete each one.
        foreach ($pks as $i => $pk) {
            if ($table->load($pk)) {
                if ($this->canDelete($table)) {
                    $context = $this->option . '.' . $this->name;

                    // Trigger the before delete event.
                    $result = Factory::getApplication()->triggerEvent($this->event_before_delete, [$context, $table]);

                    if (\in_array(false, $result, true)) {
                        $this->setError($table->getError());

                        return false;
                    }

                    $tourId = $table->id;

                    if (!$table->delete($pk)) {
                        $this->setError($table->getError());

                        return false;
                    }

                    // Delete of the tour has been successful, now delete the steps
                    $db    = $this->getDatabase();
                    $query = $db->getQuery(true)
                        ->delete($db->quoteName('#__guidedtour_steps'))
                        ->where($db->quoteName('tour_id') . '=' . $tourId);
                    $db->setQuery($query);
                    $db->execute();

                    // Trigger the after event.
                    Factory::getApplication()->triggerEvent($this->event_after_delete, [$context, $table]);
                } else {
                    // Prune items that you can't change.
                    unset($pks[$i]);
                    $error = $this->getError();

                    if ($error) {
                        Log::add($error, Log::WARNING, 'jerror');

                        return false;
                    } else {
                        Log::add(Text::_('JLIB_APPLICATION_ERROR_DELETE_NOT_PERMITTED'), Log::WARNING, 'jerror');

                        return false;
                    }
                }
            } else {
                $this->setError($table->getError());

                return false;
            }
        }

        // Clear the component's cache
        $this->cleanCache();

        return true;
    }

    /**
     * Duplicate all steps if a tour is duplicated
     *
     * @param   object  $pks  The primary key related to the tours.
     *
     * @return  boolean
     *
     * @since   4.3.0
     */
    public function duplicate(&$pks)
    {
        $user = $this->getCurrentUser();
        $db   = $this->getDatabase();

        // Access checks.
        if (!$user->authorise('core.create', 'com_tours')) {
            throw new \Exception(Text::_('JERROR_CORE_CREATE_NOT_PERMITTED'));
        }

        $table = $this->getTable();

        $date = Factory::getDate()->toSql();

        foreach ($pks as $pk) {
            if ($table->load($pk, true)) {
                // Reset the id to create a new record.
                $table->id = 0;

                $table->published   = 0;

                if (!$table->check() || !$table->store()) {
                    throw new \Exception($table->getError());
                }

                $pk = (int) $pk;

                $query = $db->getQuery(true)
                    ->select(
                        $db->quoteName(
                            [
                                'title',
                                'description',
                                'ordering',
                                'position',
                                'target',
                                'type',
                                'interactive_type',
                                'url',
                                'created',
                                'modified',
                                'checked_out_time',
                                'checked_out',
                                'language',
                                'note',
                            ]
                        )
                    )
                    ->from($db->quoteName('#__guidedtour_steps'))
                    ->where($db->quoteName('tour_id') . ' = :id')
                    ->bind(':id', $pk, ParameterType::INTEGER);

                $db->setQuery($query);
                $rows = $db->loadObjectList();

                $query = $db->getQuery(true)
                    ->insert($db->quoteName('#__guidedtour_steps'))
                    ->columns(
                        [
                            $db->quoteName('tour_id'),
                            $db->quoteName('title'),
                            $db->quoteName('description'),
                            $db->quoteName('ordering'),
                            $db->quoteName('position'),
                            $db->quoteName('target'),
                            $db->quoteName('type'),
                            $db->quoteName('interactive_type'),
                            $db->quoteName('url'),
                            $db->quoteName('created'),
                            $db->quoteName('created_by'),
                            $db->quoteName('modified'),
                            $db->quoteName('modified_by'),
                            $db->quoteName('language'),
                            $db->quoteName('note'),
                        ]
                    );

                foreach ($rows as $step) {
                    $dataTypes = [
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::INTEGER,
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::STRING,
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::INTEGER,
                        ParameterType::STRING,
                        ParameterType::STRING,
                    ];

                    $query->values(
                        implode(
                            ',',
                            $query->bindArray(
                                [
                                    $table->id,
                                    $step->title,
                                    $step->description,
                                    $step->ordering,
                                    $step->position,
                                    $step->target,
                                    $step->type,
                                    $step->interactive_type,
                                    $step->url,
                                    $date,
                                    $user->id,
                                    $date,
                                    $user->id,
                                    $step->language,
                                    $step->note,
                                ],
                                $dataTypes
                            )
                        )
                    );
                }

                $db->setQuery($query);

                try {
                    $db->execute();
                } catch (\RuntimeException $e) {
                    Factory::getApplication()->enqueueMessage($e->getMessage(), 'error');

                    return false;
                }
            } else {
                throw new \Exception($table->getError());
            }
        }

        // Clear tours cache
        $this->cleanCache();

        return true;
    }

    /**
     * Sets a tour's steps language
     *
     * @param   int     $id        Id of a tour
     * @param   string  $language  The language to apply to the steps belong the tour
     *
     * @return  boolean
     *
     * @since  4.3.0
     */
    protected function setStepsLanguage(int $id, string $language = '*'): bool
    {
        if ($id <= 0) {
            return false;
        }

        $db    = $this->getDatabase();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__guidedtour_steps'))
            ->set($db->quoteName('language') . ' = :language')
            ->where($db->quoteName('tour_id') . ' = :tourId')
            ->bind(':language', $language)
            ->bind(':tourId', $id, ParameterType::INTEGER);

        return $db->setQuery($query)
            ->execute();
    }
}
