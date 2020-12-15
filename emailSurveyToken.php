<?php
/**
 * Tool for others plugins
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2018 Denis Chenu <http://www.sondages.pro>
 * @license GPL v3
 * @version 0.0.2
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
class emailSurveyToken extends PluginBase {

    static protected $description = 'A tool for other plugins';
    static protected $name = 'emailSurveyToken';
    
    public function init() {
        Yii::setPathOfAlias(get_class($this), dirname(__FILE__));
        /* Update Yii config */
        $this->subscribe('afterPluginLoad');
    }

    /**
     * Add this translation just after loaded all plugins
     * Don't use default LimeSurvey system to have a fixed named component
     * Usage for other class and plugin `Yii::t('',$string,array(),'emailSurveyToken');`
     * @see event afterPluginLoad
     */
    public function afterPluginLoad(){
        if (!$this->getEvent()) {
            throw new CHttpException(403);
        }
        // messageSource for this plugin:
        $messageSource=array(
            'class' => 'CGettextMessageSource',
            'cacheID' => get_class($this).'Lang',
            'cachingDuration'=>3600,
            'forceTranslation' => true,
            'useMoFile' => true,
            'basePath' => __DIR__ . DIRECTORY_SEPARATOR.'locale',
            'catalog'=>'messages',// default from Yii
        );
        Yii::app()->setComponent(get_class($this),$messageSource);
    }

}
