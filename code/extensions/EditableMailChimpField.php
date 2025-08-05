<?php

/**
 * Creates an editable field that allows users to choose a list
 * From MailChimp and choose default fields
 * On submission of the form a new subscription will be created
 *
 * @package mailchimp-userform
 */

namespace Swordfox\UserForms;

use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\ArrayList;
use SilverStripe\Forms\HiddenField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\CheckboxField;
use SilverStripe\Forms\LiteralField;
use Symbiote\GridFieldExtensions\GridFieldEditableColumns;
use Symbiote\GridFieldExtensions\GridFieldAddNewInlineButton;
use Symbiote\GridFieldExtensions\GridFieldOrderableRows;
use Symbiote\GridFieldExtensions\GridFieldTitleHeader;
use DrewM\MailChimp\MailChimp;

use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldButtonRow;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldToolbarHeader;

use Swordfox\UserForms\EditableMergeField;

class EditableMailChimpField extends EditableFormField
{
    private static $table_name = 'EditableMailChimpField';

    /**
     * @var string
     */
    private static $singular_name = 'MailChimp Signup Field';

    /**
     * @var string
     */
    private static $plural_name = 'MailChimp Signup Fields';

    /**
     * @var array Fields on the user defined form page.
     */
    private static $db = [
        'FieldType' => 'Enum(array("CheckboxField","HiddenField"),"CheckboxField")',
        'ListID' => 'Varchar(255)',
        'TagsToAssign' => 'Varchar(255)',
        'EmailField' => 'Varchar(255)',
        'FirstNameField' => 'Varchar(255)',
        'LastNameField' => 'Varchar(255)',
        'UpdateContact' => 'Boolean'
    ];

    private static $has_many = [
        'MergeFields' => EditableMergeField::class,
    ];

    private static $owns = [
        'MergeFields',
    ];

    private static $cascade_deletes = [
        'MergeFields',
    ];

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();


        $editableColumns = new GridFieldEditableColumns();
        $editableColumns->setDisplayFields([
            'FieldID' => [
                'title' => 'Field',
                'callback' => function ($record, $column, $grid) {
                    return DropdownField::create($column, $column, $this->Parent()->Fields()->excludeAny(
                        [
                            'Title' => null,
                            'ID' => $this->ID,
                            'ClassName' => [
                                'SilverStripe\UserForms\Model\EditableFormField\EditableFormStep',
                                'SilverStripe\UserForms\Model\EditableFormField\EditableCheckboxGroupField',
                                'SilverStripe\UserForms\Model\EditableFormField\EditableRadioField',
                                'SilverStripe\UserForms\Model\EditableFormField\EditableFieldGroup',
                                'SilverStripe\UserForms\Model\EditableFormField\EditableFieldGroupEnd'
                            ],
                        ]
                    )->map('ID', 'Title'));
                }
            ],
            'Value' => [
                'title' => 'Merge Field (e.g. FNAME, LNAME, ADDRESS or PHONE etc.)',
                'callback' => function ($record, $column, $grid) {
                    return TextField::create($column);
                }
            ]
        ]);

        $MergeFieldsConfig = GridFieldConfig::create()
            ->addComponents(
                new GridFieldToolbarHeader(),
                new GridFieldTitleHeader(),
                new GridFieldOrderableRows('Sort'),
                $editableColumns,
                new GridFieldButtonRow(),
                new GridFieldAddNewInlineButton(),
                new GridFieldDeleteAction()
            );

        $MergeFieldsGrid = GridField::create(
            'MergeFields',
            'Merge Fields',
            $this->MergeFields(),
            $MergeFieldsConfig
        );

        $fields->insertAfter('Main', Tab::create('MergeFields', 'Merge Fields'));
        $fields->addFieldToTab('Root.MergeFields', $MergeFieldsGrid);


        // get current user form fields
        $currentFromFields = $this->Parent()->Fields()->map('Name', 'Title')->toArray();

        // check for any lists
        $fieldsStatus = true;
        if ($this->getLists()->Count() > 0) {
            $fieldsStatus = false;
        }

        $FieldTypeValues = ($this->owner::get()->dbObject('FieldType')->enumValues());

        $fields->addFieldsToTab(
            "Root.Main",
            array(
                LiteralField::create("MailChimpStart", "<h4>MailChimp Configuration</h4>")->setAttribute("disabled", $fieldsStatus),
                DropdownField::create("ListID", 'Subscribers List', $this->getLists()->map("ListID", "Name"))
                    ->setEmptyString("Choose a MailChimp List")
                    ->setAttribute("disabled", $fieldsStatus),
                TextField::create("TagsToAssign", 'Tags To Assign')
                    ->setDescription('Separete each tag with a comma')
                    ->setAttribute("disabled", $fieldsStatus),    
                DropdownField::create("EmailField", 'Email Field', $currentFromFields)->setAttribute("disabled", $fieldsStatus),
                DropdownField::create("FirstNameField", 'First Name Field', $currentFromFields)->setAttribute("disabled", $fieldsStatus),
                DropdownField::create("LastNameField", 'Last Name Field', $currentFromFields)->setAttribute("disabled", $fieldsStatus),
                LiteralField::create("MailChimpEnd", "<h4>Other Configuration</h4>"),
                DropdownField::create("FieldType", 'Field Type', $FieldTypeValues),
                CheckboxField::create("UpdateContact", 'Update Contact')
                    ->setDescription('Updates the contact if it already exists in the selected MailChimp list'),
            ),
            'Type'
        );

        $editableColumns = new GridFieldEditableColumns();
        $editableColumns->setDisplayFields(
            array(
                'Title' => array(
                    'title' => 'Title',
                    'callback' => function ($record, $column, $grid) {
                        return TextField::create($column);
                    }
                ),
                'Default' => array(
                    'title' => _t('EditableMultipleOptionField.DEFAULT', 'Selected by default?'),
                    'callback' => function ($record, $column, $grid) {
                        return CheckboxField::create($column);
                    }
                )
            )
        );

        return $fields;
    }

    /**
     * @return NumericField
     */
    public function getFormField()
    {
        // ensure format and data is correct based on type
        if ($this->FieldType == 'HiddenField') {
            $field = HiddenField::create($this->Name, $this->Title, 1);
        } else {
            $field = CheckboxField::create($this->Name, $this->Title);
        }

        $this->doUpdateFormField($field);
        return $field;
    }

    /**
     * @return Boolean/Result
     */
    public function getValueFromData($data)
    {
        // if this field was set and there are lists - subscriper the user
        if (isset($data[$this->Name]) && $this->owner->getField('ListID')) {
            $this->extend('beforeValueFromData', $data);
            $api_key = $this->config()->get('api_key');

            $MailChimp = new MailChimp($api_key);

            $list_id = $this->owner->getField('ListID');

            $tags = null;

            if ($this->owner->getField('TagsToAssign') != '') {
                $tags = preg_split('/\s*,\s*/', $this->owner->getField('TagsToAssign'), -1, PREG_SPLIT_NO_EMPTY);
            }

            $emailaddress = $data[$this->owner->getField('EmailField')];

            $mergefields = [];
            if ($this->owner->getField('FirstNameField')) {
                $mergefields['FNAME'] = $data[$this->owner->getField('FirstNameField')];
            }
            if ($this->owner->getField('LastNameField')) {
                $mergefields['LNAME'] = $data[$this->owner->getField('LastNameField')];
            }

            foreach ($this->MergeFields() as $MergeField) {
                if (isset($data[$MergeField->Field()->Name])) {
                    $mergefields[$MergeField->Value] = $data[$MergeField->Field()->Name];
                }
            }

            $data = [
                'email_address' => $emailaddress,
                'status'        => 'subscribed',
                'tags'          => [],
                'merge_fields' => $mergefields,
            ];

            if ($tags) {
                $data['tags'] = $tags;
            }

            $result = $MailChimp->post(
                "lists/$list_id/members",
                $data
            );
            
            if ($this->owner->getField('UpdateContact') and array_key_exists('status', $result) and $result['status'] == 400) {
                $subscriber_hash = MailChimp::subscriberHash($emailaddress);
                
                $data = [
                    'merge_fields' => $mergefields,
                ];
    
                if ($tags) {
                    $data['tags'] = $tags;
                }

                $result = $MailChimp->patch(
                    "lists/$list_id/members/$subscriber_hash",
                    $data
                );
            }

            $this->extend('afterValueFromData', $result);

            if ($MailChimp->success()) {
                return "Subscribed";
            } else {
                return "Not subscribed";
            }
        }

        return false;
    }

    /**
     * @return Boolean
     */
    public function getFieldValidationOptions()
    {
        return false;
    }

    /**
     * @return ArrayList
     */
    public function getLists()
    {
        $api_key = $this->config()->get('api_key');

        $MailChimp = new MailChimp($api_key);

        $result = $MailChimp->get('lists');
        $cLists = array();
        if ($MailChimp->success()) {
            foreach ($result['lists'] as $list) {
                $cLists[] = new ArrayData(array("ListID" => $list['id'], "Name" => $list['name']));
            }
        }

        $this->extend('updateLists', $cLists);

        return new ArrayList($cLists);
    }
}
