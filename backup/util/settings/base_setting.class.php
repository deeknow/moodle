<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    moodlecore
 * @subpackage backup-settings
 * @copyright  2010 onwards Eloy Lafuente (stronk7) {@link http://stronk7.com}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * This abstract class defines one basic setting
 *
 * Each setting will be able to control its name, value (from a list), ui
 * representation (check box, drop down, text field...), visibility, status
 * (editable/locked...) and its hierarchy with other settings (using one
 * like-observer pattern.
 *
 * TODO: Finish phpdocs
 */
abstract class base_setting {

    // Some constants defining different ui representations for the setting
    const UI_NONE             = 0;
    const UI_HTML_CHECKBOX    = 10;
    const UI_HTML_RADIOBUTTON = 20;
    const UI_HTML_DROPDOWN    = 30;
    const UI_HTML_TEXTFIELD   = 40;

    // Type of validation to perform against the value (relaying in PARAM_XXX validations)
    const IS_BOOLEAN = 'bool';
    const IS_INTEGER = 'int';
    const IS_FILENAME= 'file';
    const IS_PATH    = 'path';

    // Visible/hidden
    const VISIBLE = 1;
    const HIDDEN  = 0;

    // Editable/locked (by different causes)
    const NOT_LOCKED           = 3;
    const LOCKED_BY_CONFIG     = 5;
    const LOCKED_BY_HIERARCHY  = 7;
    const LOCKED_BY_PERMISSION = 9;

    // Type of change to inform dependencies
    const CHANGED_VALUE      = 1;
    const CHANGED_VISIBILITY = 2;
    const CHANGED_STATUS     = 3;

    protected $name;  // name of the setting
    protected $value; // value of the setting
    protected $vtype; // type of value (setting_base::IS_BOOLEAN/setting_base::IS_INTEGER...)

    protected $visibility; // visibility of the setting (setting_base::VISIBLE/setting_base::HIDDEN)
    protected $status; // setting_base::NOT_LOCKED/setting_base::LOCKED_BY_PERMISSION...

    protected $dependencies; // array of dependent (observer) objects (usually setting_base ones)

    // Note: all the UI stuff could go to independent classes in the future...
    protected $ui_type;   // setting_base::UI_HTML_CHECKBOX/setting_base::UI_HTML_RADIOBUTTON...
    protected $ui_label;  // UI label of the setting
    protected $ui_values; // array of value => ui value of the setting
    protected $ui_options;// array of custom ui options

    public function __construct($name, $vtype, $value = null, $visibility = self::VISIBLE, $status = self::NOT_LOCKED) {
        // Check vtype
        if ($vtype !== self::IS_BOOLEAN && $vtype !== self::IS_INTEGER &&
            $vtype !== self::IS_FILENAME && $vtype !== self::IS_PATH) {
            throw new base_setting_exception('setting_invalid_type');
        }

        // Validate value
        $value = $this->validate_value($vtype, $value);

        // Check visibility
        $visibility = $this->validate_visibility($visibility);

        // Check status
        $status = $this->validate_status($status);

        $this->name        = $name;
        $this->vtype       = $vtype;
        $this->value       = $value;
        $this->visibility  = $visibility;
        $this->status      = $status;
        $this->dependencies= array();

        // Apply these defaults
        $this->ui_type    = self::UI_HTML_DROPDOWN;
        $this->ui_label   = $name;
        $this->ui_values  = array();
        $this->ui_options = array();
    }

    public function get_name() {
        return $this->name;
    }

    public function get_value() {
        return $this->value;
    }

    public function get_visibility() {
        return $this->visibility;
    }

    public function get_status() {
        return $this->status;
    }

    public function set_value($value) {
        // Validate value
        $value = $this->validate_value($this->vtype, $value);
        // Only can change value if setting is not locked
        if ($this->status != self::NOT_LOCKED) {
            switch ($this->status) {
                case self::LOCKED_BY_PERMISSION:
                    throw new base_setting_exception('setting_locked_by_permission');
                case self::LOCKED_BY_HIERARCHY:
                    throw new base_setting_exception('setting_locked_by_hierarchy');
            }
        }
        $oldvalue = $this->value;
        $this->value = $value;
        if ($value !== $oldvalue) { // Value has changed, let's inform dependencies
            $this->inform_dependencies(self::CHANGED_VALUE, $oldvalue);
        }
    }

    public function set_visibility($visibility) {
        $visibility = $this->validate_visibility($visibility);
        $oldvisibility = $this->visibility;
        $this->visibility = $visibility;
        if ($visibility !== $oldvisibility) { // Visibility has changed, let's inform dependencies
            $this->inform_dependencies(self::CHANGED_VISIBILITY, $oldvisibility);
        }
    }

    public function set_status($status) {
        $status = $this->validate_status($status);
        $oldstatus = $this->status;
        $this->status = $status;
        if ($status !== $oldstatus) { // Status has changed, let's inform dependencies
            $this->inform_dependencies(self::CHANGED_STATUS, $oldstatus);
        }
    }

    public function set_ui($type, $label, $values, $options) {
        $type = $this->validate_ui_type($type);
        $label =$this->validate_ui_label($label);
        $this->ui_type    = $type;
        $this->ui_label   = $label;
        $this->set_ui_values($values);
        $this->set_ui_options($options);
    }

    public function set_ui_values($values) {
        $this->ui_values = $values;
    }

    public function set_ui_options($options) {
        $this->ui_options = $options;
    }

    public function add_dependency($obj) {
        if ($this->is_circular_reference($obj)) {
            $a = new stdclass();
            $a->alreadydependent = $this->name;
            $a->main = $obj->get_name();
            throw new base_setting_exception('setting_circular_reference', $a);
        }
        // Check the settings hasn't been already added
        if (array_key_exists($obj->get_name(), $this->dependencies)) {
            throw new base_setting_exception('setting_already_added');
        }
        $this->dependencies[$obj->get_name()] = $obj;
    }

// Protected API starts here

    protected function validate_value($vtype, $value) {
        if (is_null($value)) { // Nulls aren't validated
            return null;
        }
        $oldvalue = $value;
        switch ($vtype) {
            case self::IS_BOOLEAN:
                $value = clean_param($oldvalue, PARAM_BOOL); // Just clean
                break;
            case self::IS_INTEGER:
                $value = clean_param($oldvalue, PARAM_INT);
                if ($value != $oldvalue) {
                    throw new base_setting_exception('setting_invalid_integer', $oldvalue);
                }
                break;
            case self::IS_FILENAME:
                $value = clean_param($oldvalue, PARAM_FILE);
                if ($value != $oldvalue) {
                    throw new base_setting_exception('setting_invalid_filename', $oldvalue);
                }
                break;
            case self::IS_PATH:
                $value = clean_param($oldvalue, PARAM_PATH);
                if ($value != $oldvalue) {
                    throw new base_setting_exception('setting_invalid_path', $oldvalue);
                }
                break;
        }
        return $value;
    }

    protected function validate_visibility($visibility) {
        if (is_null($visibility)) {
            $visibility = self::VISIBLE;
        }
        if ($visibility !== self::VISIBLE && $visibility !== self::HIDDEN) {
            throw new base_setting_exception('setting_invalid_visibility');
        }
        return $visibility;
    }

    protected function validate_status($status) {
        if (is_null($status)) {
            $status = self::NOT_LOCKED;
        }
        if ($status !== self::NOT_LOCKED && $status !== self::LOCKED_BY_CONFIG &&
            $status !== self::LOCKED_BY_PERMISSION && $status !== self::LOCKED_BY_HIERARCHY) {
            throw new base_setting_exception('setting_invalid_status', $status);
        }
        return $status;
    }

    protected function validate_ui_type($type) {
        if ($type !== self::UI_HTML_CHECKBOX && $type !== self::UI_HTML_RADIOBUTTON &&
            $type !== self::UI_HTML_DROPDOWN && $type !== self::UI_HTML_TEXTFIELD) {
            throw new base_setting_exception('setting_invalid_ui_type');
        }
        return $type;
    }

    protected function validate_ui_label($label) {
        if (empty($label) || $label !== clean_param($label, PARAM_ALPHAEXT)) {
            throw new base_setting_exception('setting_invalid_ui_label');
        }
        return $label;
    }

    protected function inform_dependencies($ctype, $oldv) {
        foreach ($this->dependencies as $dependency) {
            $dependency->process_change($this, $ctype, $oldv);
        }
    }

    protected function is_circular_reference($obj) {
        // Get object dependencies recursively and check (by name) if $this is already there
        $dependencies = $obj->get_dependencies();
        if (array_key_exists($this->name, $dependencies) || $obj == $this) {
            return true;
        }
        return false;
    }

    protected function get_dependencies() {
        $dependencies = array();
        foreach ($this->dependencies as $dependency) {
            $dependencies[$dependency->get_name()] = $dependency->get_name();
            $dependencies = array_merge($dependencies, $dependency->get_dependencies());
        }
        return $dependencies;
    }

// Implementable API starts here

    abstract public function process_change($setting, $ctype, $oldv);
}

/*
 * Exception class used by all the @setting_base stuff
 */
class base_setting_exception extends backup_exception {

    public function __construct($errorcode, $a=NULL, $debuginfo=null) {
        parent::__construct($errorcode, $a, $debuginfo);
    }
}