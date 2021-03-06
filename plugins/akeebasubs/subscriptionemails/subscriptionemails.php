<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2014 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

class plgAkeebasubsSubscriptionemails extends JPlugin
{
	/**
	 * Public constructor. Overridden to load the language strings.
	 */
	public function __construct(& $subject, $config = array())
	{
		if(!is_object($config['params'])) {
			JLoader::import('joomla.registry.registry');
			$config['params'] = new JRegistry($config['params']);
		}

		parent::__construct($subject, $config);

		if (!class_exists('AkeebasubsHelperEmail'))
		{
			@include_once JPATH_ROOT . '/components/com_akeebasubs/helpers/email.php';
		}
	}

	/**
	 * Called whenever a subscription is modified. Namely, when its enabled status,
	 * payment status or valid from/to dates are changed.
	 */
	public function onAKSubscriptionChange($row, $info)
	{
		// No payment has been made yet; do not contact the user
		if($row->state == 'N') return;

		// Did the payment status just change to C or P? It's a new subscription
		if(array_key_exists('state', (array)$info['modified']) && in_array($row->state, array('P','C'))) {
			if($row->enabled) {
				if(is_object($info['previous']) && $info['previous']->state == 'P') {
					// A pending subscription just got paid
					$this->sendEmail($row, 'paid');
				} else {
					// A new subscription just got paid; send new subscription notification
					$this->sendEmail($row, 'new_active');
				}
			} elseif($row->state == 'C') {
				if($row->contact_flag <= 2) {
					// A new subscription which is for a renewal (will be active in a future date)
					$this->sendEmail($row, 'new_renewal');
				}
			} else {
				// A new subscription which is pending payment by the processor
				$this->sendEmail($row, 'new_pending');
			}
		} elseif(array_key_exists('state', (array)$info['modified']) && ($row->state == 'X')) {
			// The payment just got refused
			if(!is_object($info['previous']) || $info['previous']->state == 'N') {
				// A new subscription which could not be paid
				$this->sendEmail($row, 'cancelled_new');
			} else {
				// A pending or paid subscription which was cancelled/refunded/whatever
				$this->sendEmail($row, 'cancelled_existing');
			}
		} elseif($info['status'] == 'modified') {
			// If the subscription got disabled and contact_flag is 3, do not send out
			// an expiration notification. The flag is set to 3 only when a user has
			// already renewed his subscription.
			if(array_key_exists('enabled', (array)$info['modified']) && !$row->enabled && ($row->contact_flag == 3)) {
				return;
			} elseif(array_key_exists('enabled', (array)$info['modified']) && !$row->enabled) {
				// Disabled subscription, suppose expired
				if(($row->state == 'C')) $this->sendEmail($row, 'expired');
			} elseif(array_key_exists('enabled', (array)$info['modified']) && $row->enabled) {
				// Subscriptions just enabled, suppose date triggered
				if(($row->state == 'C')) $this->sendEmail($row, 'published');
			} elseif(array_key_exists('contact_flag', (array)$info['modified']) ) {
				// Only contact_flag change; ignore
				return;
			} else {
				// All other cases: generic email
				$this->sendEmail($row, 'generic');
			}
		}
	}

	/**
	 * Notifies the component of the supported email keys by this plugin.
	 *
	 * @return  array
	 *
	 * @since 3.0
	 */
	public function onAKGetEmailKeys()
	{
		$this->loadLanguage();
		return array(
			'section'		=> $this->_name,
			'title'			=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAILSECTION'),
			'keys'			=> array(
				'paid'					=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_PAID'),
				'new_active'			=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_NEW_ACTIVE'),
				'new_renewal'			=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_NEW_RENEWAL'),
				'new_pending'			=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_NEW_PENDING'),
				'cancelled_new'			=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_CANCELLED_NEW'),
				'cancelled_existing'	=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_CANCELLED_EXISTING'),
				'expired'				=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_EXPIRED'),
				'published'				=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_PUBLISHED'),
				'generic'				=> JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_GENERIC'),
                'offline'               => JText::_('PLG_AKEEBASUBS_SUBSCRIPTIONEMAILS_EMAIL_OFFLINE_INSTRUCTIONS'),
			)
		);
	}

	/**
	 * Sends out the email to the owner of the subscription.
	 *
	 * @param $row AkeebasubsTableSubscription The subscription row object
	 * @param $type string The type of the email to send (generic, new,)
	 */
	private function    sendEmail($row, $type = '')
	{
		// Get the user object
		$user = JFactory::getUser($row->user_id);

		// Get a preloaded mailer
		$key = 'plg_akeebasubs_' . $this->_name . '_' . $type;
		$mailer = AkeebasubsHelperEmail::getPreloadedMailer($row, $key);

		if ($mailer === false)
		{
			return false;
		}

		$mailer->addRecipient($user->email);
		$result = $mailer->Send();
		$mailer = null;

		return $result;
	}
}