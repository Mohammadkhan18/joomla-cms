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
use Joomla\CMS\MVC\Model\AdminModel;
use Joomla\CMS\Object\CMSObject;
use Joomla\CMS\Table\Table;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Item Model for a single tour.
 *
 * @since 4.3.0
 */

class StepModel extends AdminModel
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
     * @var   string
     * @since 4.3.0
     */
    public $typeAlias = 'com_guidedtours.step';

    /**
     * Method to test whether a record can be deleted.
     *
     * @param   object  $record  A record object.
     *
     * @return  boolean  True if allowed to delete the record. Defaults to the permission for the component.
     *
     * @since   4.3.0
     */
    protected function canDelete($record)
    {
        if (empty($record->id) || $record->published != -2) {
            return false;
        }

        if ($record->tour_id) {
            return $this->getCurrentUser()->authorise('core.delete', 'com_guidedtours.tour.' . $record->tour_id);
        }

        return parent::canDelete($record);
    }

    /**
     * Method to save the form data.
     *
     * @param   array  $data  The form data.
     *
     * @return  boolean  True on success.
     *
     * @since   4.3.0
     */
    public function save($data)
    {
        $table = $this->getTable();
        $input = Factory::getApplication()->getInput();

        $tour = $this->getTable('Tour');

        $tour->load($data['tour_id']);

        // Language keys must include GUIDEDTOUR to prevent save issues
        if (strpos($data['description'], 'GUIDEDTOUR') !== false) {
            $data['description'] = strip_tags($data['description']);
        }

        // Make sure we use the correct extension when editing an existing tour
        $key = $table->getKeyName();
        $pk  = isset($data[$key]) ? $data[$key] : (int) $this->getState($this->getName() . '.id');

        if ($pk > 0) {
            $table->load($pk);

            if ((int) $table->tour_id) {
                $data['tour_id'] = (int) $table->tour_id;
            }
        }

        if ($input->get('task') == 'save2copy') {
            $origTable = clone $this->getTable();
            $origTable->load($input->getInt('id'));

            $data['published'] = 0;
        }

        return parent::save($data);
    }

    /**
     * Prepare and sanitise the table prior to saving.
     *
     * @param   \Joomla\CMS\Table\Table  $table  The Table object
     *
     * @return  void
     *
     * @since   4.3.0
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
                    ->from($db->quoteName('#__guidedtour_steps'));
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
        if (!empty($record->tour_id)) {
            return $this->getCurrentUser()->authorise('core.edit.state', 'com_guidedtours.tour.' . $record->tour_id);
        }

        return parent::canEditState($record);
    }

    /**
     * Method to get a table object, load it if necessary.
     *
     * @param   string $type    The table name. Optional.
     * @param   string $prefix  The class prefix. Optional.
     * @param   array  $config  Configuration array for model. Optional.
     *
     * @return  Table  A Table object
     *
     * @since   4.3.0
     * @throws  \Exception
     */
    public function getTable($type = 'Step', $prefix = 'Administrator', $config = [])
    {
        return parent::getTable($type, $prefix, $config);
    }

    /**
     * Abstract method for getting the form from the model.
     *
     * @param   array    $data      Data for the form.
     * @param   boolean  $loadData  True if the form is to load its own data (default case), false if not.
     *
     * @return  \JForm|boolean  A JForm object on success, false on failure
     *
     * @since   4.3.0
     */
    public function getForm($data = [], $loadData = true)
    {
        // Get the form.
        $form = $this->loadForm(
            'com_guidedtours.step',
            'step',
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

        // Disables language field selection
        $form->setFieldAttribute('language', 'readonly', 'true');

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
            'com_guidedtours.edit.step.data',
            []
        );

        if (empty($data)) {
            $data = $this->getItem();
        }

        return $data;
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

        if ($result = parent::getItem($pk)) {
            if (!empty($result->id)) {
                $result->title_translation       = Text::_($result->title);
                $result->description_translation = Text::_($result->description);
            } else {
                $app    = Factory::getApplication();
                $tourId = $app->getUserState('com_guidedtours.tour_id');

                /** @var \Joomla\Component\Guidedtours\Administrator\Model\TourModel $tourModel */
                $tourModel = $app->bootComponent('com_guidedtours')
                    ->getMVCFactory()->createModel('Tour', 'Administrator', ['ignore_request' => true]);

                $tour         = $tourModel->getItem($tourId);
                $tourLanguage = !empty($tour->language) ? $tour->language : '*';

                // Sets step language to parent tour language
                $result->language = $tourLanguage;

                // Set the step's tour id
                $result->tour_id = $tourId;
            }
        }

        return $result;
    }
}
