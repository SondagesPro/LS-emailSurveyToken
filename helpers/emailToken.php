<?php
/**
 * Description
 *
 * @author Denis Chenu <denis@sondages.pro>
 * @copyright 2018 Denis Chenu <http://www.sondages.pro>
 * @license AGPL v3
 * @version 0.0.0
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */
namespace emailSurveyToken\helpers;

use Yii;
use Survey;
use SurveyLanguageSetting;
use Token;

Class emailToken
{

    /* @var integer $surveyid */
    public $surveyId;
    /* @var string $emailTemplate mail template to use */
    public $emailTemplate = 'invite';

    /* @var string[] $shownAttributes to be shown , default to firstname, lastname and attribute for register This set too the order of attribute shown */
    public $shownAttributes = array(
        'firstname',
        'lastname',
        'email',
    );

    /* @var string[] $attributes to be shown and mandatory, defaultto attribute for register */
    public $mandatoryAttributes = array(
        'email',
    );

    /* @var array $data used for email (in general : data sent by form) */
    public $dataEmail = array(
        'subject'=> '',
        'body'=> '',
        'message'=> '',
    );

    /* @var array $data used for token (in general : data sent by form) */
    public $dataToken = array();

    /* @var null|bollean validation state */
    private $isValid;

    /* @var string[] form validation errors */
    private $formErrors = array();

    /* @var string Mail error */
    public $mailError;
    /* @var string Mail error */
    public $mailDebug;

    /**
     * contructor
     * @param $surveyId integer, the survey id
     * @param $emailTemplate string template to be used
     * @return void
     */
    public function __construct($surveyId,$emailTemplate = null)
    {
        $this->surveyId = $surveyId;
        if($emailTemplate) {
            $this->emailTemplate = $emailTemplate;
        }
        $aExtraAttributes = Survey::model()->findByPk($surveyId)->getTokenAttributes();
        foreach($aExtraAttributes as $attribute => $data) {
            if($data['show_register'] == 'Y') {
                $this->shownAttributes[] = $attribute;
                if($data['mandatory'] == 'Y') {
                    $this->mandatoryAttributes[] = $attribute;
                }
            }
        }
    }

    /**
     * Get the data to make the form
     * @return array();
     */
    public function getFormData($language = null)
    {
        if(empty($language)) {
            $language = App()->getLanguage();
        }
        /* Usage of a class var ? Since it's used too in validation (to get the label) */
        $formData = array();
        $oSurvey = Survey::model()->findByPk($this->surveyId);
        $oSurveyLanguageSetting = SurveyLanguageSetting::model()->with('survey')->findByPk(array('surveyls_survey_id' => $this->surveyId, 'surveyls_language' => $language));
        if(empty($oSurveyLanguageSetting)) {
            $oSurveyLanguageSetting = SurveyLanguageSetting::model()->with('survey')->findByPk(array('surveyls_survey_id' => $this->surveyId, 'surveyls_language' => $oSurvey->language ));
        }
        $aSurveyLanguageSetting = $oSurveyLanguageSetting->getAttributes();
    
        $attributesLabel = $oSurveyLanguageSetting->attributeCaptions;
        $attributesData = $oSurveyLanguageSetting->survey->tokenAttributes;
        $formData['html'] = ($oSurvey->htmlemail == 'Y');
        $formData['subject'] = $aSurveyLanguageSetting["surveyls_email_".$this->emailTemplate."_subj"];
        $formData['body'] = $aSurveyLanguageSetting["surveyls_email_".$this->emailTemplate];
        $formData['message'] = "";
        /* construct the message : move this to twig ?*/
        $formData['helpMessage'] = "<div class='panel panel-default'>"
                                 . "<div class='panel-heading'>".Yii::t('',"Email message sent",array(),'emailSurveyToken')."</div>"
                                 . "<div class='panel-body'>"
                                 . $this->getMessageHelp($formData['body'])
                                 . "</div>"
                                 . "</div>";
        $formData['help'] = ""; // Todo
        $formData['attributes'] = array();
        foreach($this->shownAttributes as $attribute) {
            switch ($attribute) {
                case 'firstname':
                    $dataAttribute = array(
                        'id' => 'tokenAttribute_firstname',
                        'name' => 'tokenAttribute[firstname]',
                        'label' => gT("First name"),
                        'type' => 'text',
                        'required' => in_array($attribute,$this->mandatoryAttributes),
                    );
                    break;
                case 'lastname':
                    $dataAttribute = array(
                        'id' => 'tokenAttribute_lastname',
                        'name' => 'tokenAttribute[lastname]',
                        'label' => gT("Last name"),
                        'type' => 'text',
                        'required' => in_array($attribute,$this->mandatoryAttributes),
                    );
                    break;
                case 'email':
                    $dataAttribute = array(
                        'id' => 'tokenAttribute_email',
                        'name' => 'tokenAttribute[email]',
                        'label' => gT("Email"),
                        'type' => 'email',
                        'required' => in_array($attribute,$this->mandatoryAttributes),
                    );
                    break;
                default:
                    $label = $attribute;
                    if(!empty($attributesData[$attribute]['description'])) {
                        $label = $attributesData[$attribute]['description'];
                    }
                    if(!empty($attributesLabel[$attribute])) {
                        $label = $attributesData[$attribute];
                    }
                    $dataAttribute = array(
                        'id' => 'tokenAttribute_'.$attribute,
                        'name' => 'tokenAttribute['.$attribute.']',
                        'label' => $label,
                        'type' => 'text',
                        'required' => in_array($attribute,$this->mandatoryAttributes),
                    );
            }
            $formData['attributes'][$attribute] = $dataAttribute;
        }
        return $formData;
    }

    public function getFormErrors()
    {
        if(is_null($this->isValid)) {
            $this->validateForm();
        }
        return $this->formErrors;
    }
    /**
     * Validate the form and return attribute to be set
     * Auto set subject/body according to data sent.
     * @param $aDataToken string[] array of data @see self::getFormData and $this->dataToken
     * @return null|string[]
     */
    public function validateForm($aDataToken = array())
    {
        $this->formErrors =array();

        if(!empty($aDataToken)){
            $this->dataToken = array_merge($this->dataToken,$aDataToken);
        }
        if(empty($this->dataToken)) {
            $this->dataToken = Yii::app()->getRequest()->getParam('tokenAttribute');
        }
        $aFormData = $this->getFormData();
        /* Start by attributes */
        foreach($this->shownAttributes as $attribute) {
            if(in_array($attribute,$this->mandatoryAttributes) && empty($this->dataToken[$attribute])) {
                $this->formErrors[$attribute] = sprintf(gT("%s cannot be left empty."), $aFormData[$attribute]['label']);
                $this->dataToken[$attribute] = false; // false or "" ?
            }
            if($attribute == 'email' && !empty($this->dataToken[$attribute])) {
                if(!validateEmailAddress($this->dataToken['email'])) {
                    $this->formErrors['email'] = gT("The email you used is not valid. Please try again.");
                } else {
                    // Track down existing broken email
                    $oToken = Token::model($this->surveyId)->find("email =:email and emailstatus != :emailstatus",array(":email"=>$this->dataToken['email'],":emailstatus"=>"OK"));
                    if($oToken) {
                        if(strtolower(substr(trim($oToken->emailstatus), 0, 6)) === "optout") {
                            $this->formErrors['email'] = gT("This email address cannot be used because it was opted out of this survey.");
                        } else {
                            $this->formErrors['email'] = gT("This email address is already registered but the email adress was bounced.");
                        }
                    }
                }
            }
            /* we need filtering or not ? */
        }
        // Need a way to validate subject and body ? Need an extra var ?
        if(empty($this->formErrors)) {
            $this->isValid = true;
            return;
        }
        $this->isValid = false;
        return $this->formErrors;
    }

    /**
     * Created the new Token
     * If token are not created : use getErrors
     * @return \Token
     */
    public function getNewToken($aDataTokenEmail = array())
    {
        $oToken = Token::create($this->surveyId);
        if(is_null($this->isValid)) {
            $this->validateForm($aDataTokenEmail);
        }
        if(!$this->isValid) {
            $oToken->addErrors($this->formErrors);
            return $oToken;
        }
        $oToken->setAttributes($this->dataToken,false);
        if(!$oToken->save()) {
            return $oToken;
        }
        $oToken->generateToken();
        $oToken->save();
        return $oToken;
    }

    /**
     * Send the email
     * @param \Token
     * @param null|string[] data for email
     * @param string[] replacement field (without '{ }')
     * @return boolean
     */
    public function sendMail($oToken,$aDataEmail = null,$aReplaceField = array())
    {
        $this->dataEmail = array_merge($this->dataEmail,$aDataEmail);
        $sLanguage = App()->language;
        $aSurveyInfo = getSurveyInfo($this->surveyId, $sLanguage);
        $aMail = array();
        $aMail['subject'] = $this->dataEmail['subject'];
        if(trim($aMail['subject']) == "") {
            $aMail['subject'] = $aSurveyInfo['email_'.$this->emailTemplate.'_subj'];
        }
        $aMail['body'] = $this->dataEmail['body'];
        if(trim($aMail['body']) == "") {
            $aMail['body'] = $aSurveyInfo['email_'.$this->emailTemplate];
        }
        $aSurveyInfo['adminemail'] = empty($aSurveyInfo['adminemail']) ? App()->getConfig('siteadminemail') : $aSurveyInfo['adminemail'];
        $aReplacementFields = array();
        $aReplacementFields["{ADMINNAME}"] = $aSurveyInfo['admin'];
        $aReplacementFields["{ADMINEMAIL}"] = empty($aSurveyInfo['adminemail']) ? App()->getConfig('siteadminemail') : $aSurveyInfo['adminemail'];
        $aReplacementFields["{SURVEYNAME}"] = $aSurveyInfo['name'];
        $aReplacementFields["{SURVEYDESCRIPTION}"] = $aSurveyInfo['description'];
        $aReplacementFields["{EXPIRY}"] = $aSurveyInfo["expiry"];
        foreach ($oToken->attributes as $attribute=>$value) {
            $aReplacementFields["{".strtoupper($attribute)."}"] = $value;
        }
        if(!empty($aMail['message'])) {
            $aReplacementFields["{SURVEYDESCRIPTION}"] = $sMessage;
            $aReplacementFields["{MESSAGE}"] = $sMessage;
        }
        foreach($aReplaceField as $value => $replaced) {
            $aReplacementFields["{".$value."}"] = $replaced;
        }

        $sToken = $oToken->token;
        $useHtmlEmail = (getEmailFormat($this->surveyId) == 'html');
        $aMail['subject'] = preg_replace("/{TOKEN:([A-Z0-9_]+)}/", "{"."$1"."}", $aMail['subject']);
        $aMail['body'] = preg_replace("/{TOKEN:([A-Z0-9_]+)}/", "{"."$1"."}", $aMail['body']);
        $aReplacementFields["{SURVEYURL}"] = Yii::app()->getController()->createAbsoluteUrl("survey/index", array('sid'=>$this->surveyId,'token'=>$sToken));
        $aReplacementFields["{OPTOUTURL}"] = "";
        $aReplacementFields["{OPTINURL}"] = "";

        foreach (array('OPTOUT', 'OPTIN', 'SURVEY') as $key) {
            $url = $aReplacementFields["{{$key}URL}"];
            if ($useHtmlEmail) {
                $aReplacementFields["{{$key}URL}"] = "<a href='{$url}'>".htmlspecialchars($url).'</a>';
            }
            $aMail['subject'] = str_replace("@@{$key}URL@@", $url, $aMail['subject']);
            $aMail['message'] = str_replace("@@{$key}URL@@", $url, $aMail['body']);
        }
        // Replace the fields
        $aMail['subject'] = ReplaceFields($aMail['subject'], $aReplacementFields);
        $aMail['body'] = ReplaceFields($aMail['body'], $aReplacementFields);
        $sFrom = $aSurveyInfo['adminemail'];
        if(!empty($aSurveyInfo['admin'])) {
            $sFrom = $aSurveyInfo['admin']." <".$aSurveyInfo['adminemail'].">";
        }
        if(empty($aMail['subject'])) {
          $this->mailError ="Subject of message is empty";
          return false;
        }
        if(empty($aMail['body'])) {
          $this->mailError ="Body of message is empty";
          return false;
        }
        $sBounce = getBounceEmail($this->surveyId);
        $sTo = $oToken->email;
        $sitename = Yii::app()->getConfig('sitename');
        // Plugin event for email handling (Same than admin token but with register type)
        //~ $event = new PluginEvent('beforeTokenEmail');
        //~ $event->set('survey', $surveyId);
        //~ $event->set('type', 'register');
        //~ $event->set('model', 'register');
        //~ $event->set('subject', $aMail['subject']);
        //~ $event->set('to', $sTo);
        //~ $event->set('body', $aMail['message']);
        //~ $event->set('from', $sFrom);
        //~ $event->set('bounce', $sBounce);
        //~ $event->set('token', $oToken->attributes);
        //~ App()->getPluginManager()->dispatchEvent($event);
        //~ $aMail['subject'] = $event->get('subject');
        //~ $aMail['message'] = $event->get('body');
        //~ $sTo = $event->get('to');
        //~ $sFrom = $event->get('from');
        //~ $sBounce = $event->get('bounce');

        $aRelevantAttachments = array();
        if (isset($aSurveyInfo['attachments'])) {
            $aAttachments = unserialize($aSurveyInfo['attachments']);
            if (!empty($aAttachments)) {
                if (isset($aAttachments['registration'])) {
                    LimeExpressionManager::singleton()->loadTokenInformation($aSurveyInfo['sid'], $sToken);

                    foreach ($aAttachments['registration'] as $aAttachment) {
                        if (LimeExpressionManager::singleton()->ProcessRelevance($aAttachment['relevance'])) {
                            $aRelevantAttachments[] = $aAttachment['url'];
                        }
                    }
                }
            }
        }
        global $maildebug, $maildebugbody;
        Yii::app()->setConfig("emailsmtpdebug",0);
        if (false) { /* for event */
            $this->sMessage = $event->get('message', $this->sMailMessage); // event can send is own message
            if ($event->get('error') == null) {
                $today = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig('timeadjust'));
                $oToken->sent = $today;
                $oToken->save();
                return true;
            }
        } elseif (SendEmailMessage($aMail['body'], $aMail['subject'], $sTo, $sFrom, $sitename, $useHtmlEmail, $sBounce, $aRelevantAttachments)) {
            $today = dateShift(date("Y-m-d H:i:s"), "Y-m-d H:i", Yii::app()->getConfig('timeadjust'));
            $oToken->sent = $today;
            $oToken->save();
            return true;
        }
        /* todo : add error of email */
        $this->mailError = $maildebug;
        $this->mailDebug = $sFrom;
        return false;
    }

    /**
     * get the string for help with message replacer
     * @param string $mailTemplate
     * @param string[] $aReplacement extra replacement to be done in help
     * @retunr string : a helper for simple user
     */
    public function getMessageHelp($mailTemplate,$aExtraReplacement = array())
    {
        /* Start by removing header, get only body */
        preg_match("/<body[^>]*>(.*?)<\/body>/is", $mailTemplate, $aMailTemplate);
        if(isset($aMailTemplate[1])) {
            $mailTemplate = $aMailTemplate[1];
        }
        /* Remove image (?) */
        $mailTemplate = preg_replace("/<img[^>]+\>/i", "", $mailTemplate);
        $aReplacement = array(
            '{FIRSTNAME}' => "<em class='text-info'>".Yii::t('',"(First name indicated)",array(),'emailSurveyToken')."</em>",
            '{LASTNAME}' => "<em class='text-info'>".Yii::t('',"(Last name indicated)",array(),'emailSurveyToken')."</em>",
            '{SURVEYURL}' => "<em class='text-info'>".Yii::t('',"(Link automatically generated)",array(),'emailSurveyToken')."</em>",
            '{SURVEYNAME}' => "<em class='text-info'>".Yii::t('',"(This survey name)",array(),'emailSurveyToken')."</em>",
            '{SURVEYDESCRIPTION}' => "<em class='text-info'>".Yii::t('',"(your message)",array(),'emailSurveyToken')."</em>",
            '{MESSAGE}' => "<em class='text-info'>".Yii::t('',"(your message)",array(),'emailSurveyToken')."</em>",
            '{ADMINNAME}' => "<em class='text-info'>".Yii::t('',"(your contact information (First name, Last name))",array(),'emailSurveyToken')."</em>",
            '{ADMINEMAIL}' => "<em class='text-info'>".Yii::t('',"(your email)",array(),'emailSurveyToken')."</em>",
        );
        $aReplacement = array_merge($aReplacement, $aExtraReplacement);
        $mailTemplate = str_replace(array_keys($aReplacement),$aReplacement,$mailTemplate);
        $filter = new \CHtmlPurifier();
        $mailTemplate = $filter->purify($mailTemplate);
        return $mailTemplate;
    }
}
