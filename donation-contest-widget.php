<?php
/*
Plugin Name: Donation Contest Widget
Plugin URI: http://www.icprojects.net/donation-contest-widget.html
Description: This plugin allows you to insert Donation Contest Widget into any widgetized area of your website.
Version: 1.28
Author: Ivan Churakov
Author URI: http://www.freelancer.com/affiliates/ichurakov/
*/

wp_enqueue_script("jquery");
register_activation_hook(__FILE__, array("donationcontest_class", "install"));

class donationcontest_class
{
	private $options;
	private $error;
	private $info;
	
	var $exists;
	var $paypal_id;
	var $admin_email;
	var $from_name;
	var $from_email;
	var	$thankyou_email_subject;
	var $thankyou_email_body;
	var $donate_type;
	var $donate_image;
	var $top_donors;
	var $slide_delay;
	var $widget_stylesheet;
	var $donate_buttons_list = array("html", "paypal", "custom");
	private $default_options;
	
	function __construct() {
		$this->options = array(
		"exists",
		"paypal_id",
		"admin_email",
		"from_name",
		"from_email",
		"thankyou_email_subject",
		"thankyou_email_body",
		"donate_type",
		"donate_image",
		"top_donors",
		"widget_stylesheet",
		"slide_delay"
		);
		$this->default_options = array (
			"exists" => 1,
			"paypal_id" => "donations@".str_replace("www.", "", $_SERVER["SERVER_NAME"]),
			"admin_email" => "alerts@".str_replace("www.", "", $_SERVER["SERVER_NAME"]),
			"from_name" => get_bloginfo("name"),
			"from_email" => "noreply@".str_replace("www.", "", $_SERVER["SERVER_NAME"]),
			"thankyou_email_subject" => "Donation received",
			"thankyou_email_body" => "Dear {first_name},\r\n\r\nWe would like to thank you for your donation. We really appreciate it.\r\n\r\nThanks,\r\nAdministration of ".get_bloginfo("name"),
			"donate_type" => "paypal",
			"donate_image" => "",
			"top_donors" => "5",
			"widget_stylesheet" => ".dontaioncontest_widgetbox {\r\nborder: 2px solid #C0C0C0 !important;\r\nbackground-color: #F8F8F8 !important;\r\ntext-align: center !important;\r\npadding: 5px 10px !important;\r\nmax-width: 300px !important;\r\nline-height: 20px;\r\nfont-size: 13px;\r\n}\r\n.dontaioncontest_widgetbox h3 {\r\nfont-weight: bold !important;\r\nfont-size: 14px !important;\r\nline-height: 17px !important;\r\nmargin: 0px 0px 5px 0px !important;\r\npadding: 0px !important;\r\nborder: 0px solid transparent !important;\r\n}\r\n.dontaioncontest_button {\r\nmargin: 5px 0px 0px 0px !important;\r\n}",
			"slide_delay" => "10"
		);

		if (!empty($_COOKIE["donationcontest_error"]))
		{
			$this->error = stripslashes($_COOKIE["donationcontest_error"]);
			setcookie("donationcontest_error", "", time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
		}
		if (!empty($_COOKIE["donationcontest_info"]))
		{
			$this->info = stripslashes($_COOKIE["donationcontest_info"]);
			setcookie("donationcontest_info", "", time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
		}

		$this->get_settings();
		
		add_action("widgets_init", array(&$this, 'widgets_init'));
		if (is_admin())
		{
			if ($this->check_settings() !== true) add_action('admin_notices', array(&$this, 'admin_warning'));
			add_action('admin_menu', array(&$this, 'admin_menu'));
			add_action('init', array(&$this, 'admin_request_handler'));
			add_action('admin_head', array(&$this, 'admin_header'), 15);
		}
		else
		{
			add_action("wp_head", array(&$this, "front_header"));
		}
	}

	function install ()
	{
		global $wpdb;
		$table_name = $wpdb->prefix . "dc_transactions";
		if($wpdb->get_var("show tables like '".$table_name."'") != $table_name)
		{
			$sql = "CREATE TABLE " . $table_name . " (
				id int(11) NOT NULL auto_increment,
				payer_name varchar(255) collate utf8_unicode_ci NOT NULL,
				payer_email varchar(255) collate utf8_unicode_ci NOT NULL,
				gross float NOT NULL,
				currency varchar(15) collate utf8_unicode_ci NOT NULL,
				payment_status varchar(31) collate utf8_unicode_ci NOT NULL,
				transaction_type varchar(31) collate utf8_unicode_ci NOT NULL,
				details text collate utf8_unicode_ci NOT NULL,
				created int(11) NOT NULL,
				UNIQUE KEY  id (id)
			);";
			require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
			dbDelta($sql);
		}
	}

	function get_settings() {
		$exists = get_option('donationcontest_exists');
		if ($exists != 1)
		{
			foreach ($this->options as $option) {
				$this->$option = $this->default_options[$option];
			}
		}
		else
		{
			foreach ($this->options as $option) {
				$this->$option = get_option('donationcontest_'.$option);
			}
		}
	}

	function update_settings() {
		if (current_user_can('manage_options')) {
			foreach ($this->options as $option) {
				update_option('donationcontest_'.$option, $this->$option);
			}
		}
	}

	function populate_settings() {
		foreach ($this->options as $option) {
			if (isset($_POST['donationcontest_'.$option])) {
				$this->$option = stripslashes($_POST['donationcontest_'.$option]);
			}
		}
	}

	function check_settings() {
		$errors = array();
		if (!eregi("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$", $this->paypal_id) || strlen($this->paypal_id) == 0) $errors[] = "PayPal ID must be valid e-mail address";
		if (!eregi("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$", $this->admin_email) || strlen($this->admin_email) == 0) $errors[] = "E-mail for notifications must be valid e-mail address";
		if (!eregi("^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$", $this->from_email) || strlen($this->from_email) == 0) $errors[] = "Sender e-mail must be valid e-mail address";
		if (strlen($this->from_name) < 3) $errors[] = "Sender name is too short";
		if (strlen($this->thankyou_email_subject) < 3) $errors[] = "\"Thank you\" e-mail subject must contain at least 3 characters";
		else if (strlen($this->thankyou_email_subject) > 64) $errors[] = "\"Thank you\" e-mail subject must contain maximum 64 characters";
		if (strlen($this->thankyou_email_body) < 3) $errors[] = "\"Thank you\" e-mail body must contain at least 3 characters";
		if (intval($this->top_donors) != $this->top_donors || intval($this->top_donors) < 1 || intval($this->top_donors) > 10) $errors[] = "Download link lifetime must be valid integer value in range [1...10]";
		if (intval($this->slide_delay) != $this->slide_delay || intval($this->slide_delay) < 2 || intval($this->slide_delay) > 60) $errors[] = "Slideshow delay must be valid integer value in range [2...60]";
		if (empty($errors)) return true;
		return $errors;
	}

	function admin_menu() {
		if (current_user_can('manage_options')) {
			add_options_page(
				"Donation Contest"
				, "Donation Contest"
				, 10
				, "donation-contest-widget"
				, array(&$this, 'admin_settings')
			);
		}
	}

	function admin_settings() {
		global $wpdb;
		$message = "";
		$errors = array();
		if (!empty($this->error)) $message = "<div class='error'><p><strong>ERROR</strong>: ".$this->error."</p></div>";
		else
		{
			$errors = $this->check_settings();
			if (is_array($errors)) echo "<div class='error'><p>The following error(s) exists:<br />- ".implode("<br />- ", $errors)."</p></div>";
		}
		if ($_GET["updated"] == "true")
		{
			$message = '<div class="updated"><p>Plugin settings successfully <strong>updated</strong>.</p></div>';
		}
		if (!in_array($this->donate_type, $this->donate_buttons_list)) $this->donate_type = $this->donate_buttons_list[0];
		if ($this->donate_type == "custom")
		{
			if (empty($this->donate_image)) $this->donate_type = $this->donate_buttons_list[0];
		}
		print ('
		<div class="wrap admin_donationcontest_wrap">
			<div id="icon-options-general" class="icon32"><br /></div><h2>Donation Contest Widget</h2><br /> 
			'.$message.'
			<form enctype="multipart/form-data" method="post" style="margin: 0px" action="'.get_bloginfo('wpurl').'/wp-admin/admin.php">
			<table class="donationcontest_useroptions">
				<tr>
					<th>PayPal ID:</th>
					<td><input type="text" id="donationcontest_paypal_id" name="donationcontest_paypal_id" value="'.htmlspecialchars($this->paypal_id, ENT_QUOTES).'" style="width: 95%;"><br /><em>Please enter valid PayPal e-mail, all donations are sent to this account.</em></td>
				</tr>
				<tr>
					<th>E-mail for notifications:</th>
					<td><input type="text" id="donationcontest_admin_email" name="donationcontest_admin_email" value="'.htmlspecialchars($this->admin_email, ENT_QUOTES).'" style="width: 95%;"><br /><em>Please enter e-mail address. All donation alerts are sent to this e-mail address.</em></td>
				</tr>
				<tr>
					<th>Sender name:</th>
					<td><input type="text" id="donationcontest_from_name" name="donationcontest_from_name" value="'.htmlspecialchars($this->from_name, ENT_QUOTES).'" style="width: 95%;"><br /><em>Please enter sender name. All messages to donors are sent using this name as "FROM:" header value.</em></td>
				</tr>
				<tr>
					<th>Sender e-mail:</th>
					<td><input type="text" id="donationcontest_from_email" name="donationcontest_from_email" value="'.htmlspecialchars($this->from_email, ENT_QUOTES).'" style="width: 95%;"><br /><em>Please enter sender e-mail. All messages to donors are sent using this e-mail as "FROM:" header value.</em></td>
				</tr>
				<tr>
					<th>"Thank you" e-mail subject:</th>
					<td><input type="text" id="donationcontest_thankyou_email_subject" name="donationcontest_thankyou_email_subject" value="'.htmlspecialchars($this->thankyou_email_subject, ENT_QUOTES).'" style="width: 95%;"><br /><em>In case of successful donation, your donors receive \"thank you\" message. This is subject field of the message.</em></td>
				</tr>
				<tr>
					<th>"Thank you" e-mail body:</th>
					<td><textarea id="donationcontest_thankyou_email_body" name="donationcontest_thankyou_email_body" style="width: 95%; height: 120px;">'.htmlspecialchars($this->thankyou_email_body, ENT_QUOTES).'</textarea><br /><em>This e-mail message is sent to your donors in case of successful donations. You can use the following keywords: {first_name}, {last_name}, {payer_email}, {donation_gross}, {donation_currency}.</em></td>
				</tr>
				<tr>
					<th>"Top donors" number:</th>
					<td><input type="text" id="donationcontest_top_donors" name="donationcontest_top_donors" value="'.htmlspecialchars($this->top_donors, ENT_QUOTES).'" style="width: 60px; text-align: right;"> days<br /><em>Please enter number of top donors to be displayed in widget box.</em></td>
				</tr>
				<tr>
					<th>"Donate" button:</th>
					<td>
						<table style="border: 0px; padding: 0px;">
						<tr><td style="padding-top: 8px; width: 20px;"><input type="radio" name="donationcontest_donate_type" value="html"'.($this->donate_type == "html" ? ' checked="checked"' : '').'></td><td>Standard HTML-button<br /><button onclick="return false;">Donate</button></td></tr>
						<tr><td style="padding-top: 8px;"><input type="radio" name="donationcontest_donate_type" value="paypal"'.($this->donate_type == "paypal" ? ' checked="checked"' : '').'></td><td>Standard PayPal button<br /><img src="'.get_bloginfo("wpurl").'/wp-content/plugins/donation-contest-widget/images/btn_donate_LG.gif" border="0"></td></tr>
						<tr><td style="padding-top: 8px;"><input type="radio" name="donationcontest_donate_type" value="custom"'.($this->donate_type == "custom" ? ' checked="checked"' : '').'></td><td>Custom "Donate" button'.(!empty($this->donate_image) ? '<br /><img src="'.get_bloginfo("wpurl").'/wp-content/plugins/donation-contest-widget/uploads/'.rawurlencode($this->donate_image).'" border="0">' : '').'<br /><input type="file" id="donationcontest_donate_image" name="donationcontest_donate_image" style="width: 95%;"><br /><em>Max dimensions: 200px x 200px, allowed images: JPG, GIF, PNG.</em></td></tr>
						</table>
					</td>
				</tr>
				<tr>
					<th>Widget stylesheet:</th>
					<td><textarea id="donationcontest_widget_stylesheet" name="donationcontest_widget_stylesheet" style="width: 95%; height: 120px;">'.htmlspecialchars($this->widget_stylesheet, ENT_QUOTES).'</textarea><br /><em>You can customize widget stylesheet here.</em></td>
				</tr>
				<tr>
					<th>Slideshow delay:</th>
					<td><input type="text" id="donationcontest_slide_delay" name="donationcontest_slide_delay" value="'.htmlspecialchars($this->slide_delay, ENT_QUOTES).'" style="width: 60px; text-align: right;"> seconds<br /><em>Widget displays TOP donors for last month, last year and all the time. This information is grouped into 3 slides. Here you can set the number of seconds for displaying of each slide.</em></td>
				</tr>
				<tr>
					<td colspan="2" style="padding-top: 20px;">
					<input type="hidden" name="ak_action" value="donationcontest_update_settings" />
					<input type="hidden" name="donationcontest_exists" value="1" />
					<input type="submit" id="submit" name="submit" value="Submit">
					</td>
				</tr>
			</table>
			</form>
		</div>
		');
	}

	function admin_request_handler() {
		global $wpdb;
		if (!empty($_POST['ak_action'])) {
			switch($_POST['ak_action']) {
				case 'donationcontest_update_settings':
					$this->populate_settings();
					$donate_image = "";
					$errors_info = "";
					if (is_uploaded_file($_FILES["donationcontest_donate_image"]["tmp_name"]))
					{
						$ext = strtolower(substr($_FILES["donationcontest_donate_image"]["name"], strlen($_FILES["donationcontest_donate_image"]["name"])-4));
						if ($ext != ".jpg" && $ext != ".gif" && $ext != ".png") $errors[] = 'Custom "Donate" button has invalid image type';
						else
						{
							list($width, $height, $type, $attr) = getimagesize($_FILES["donationcontest_donate_image"]["tmp_name"]);
							if ($width > 200 || $height > 200) $errors[] = 'Custom "Donate" button has invalid image dimensions';
							else
							{
								$donate_image = "button_".md5(microtime().$_FILES["donationcontest_donate_image"]["tmp_name"]).$ext;
								if (!move_uploaded_file($_FILES["donationcontest_donate_image"]["tmp_name"], dirname(__FILE__)."/uploads/".$donate_image))
								{
									$errors[] = "Can't save uploaded image";
									$donate_image = "";
								}
								else
								{
									if (!empty($this->donate_image))
									{
										if (file_exists(dirname(__FILE__)."/uploads/".$this->donate_image) && is_file(dirname(__FILE__)."/uploads/".$this->donate_image))
											unlink(dirname(__FILE__)."/uploads/".$this->donate_image);
									}
								}
							}
						}
					}
					if (!empty($donate_image)) $this->donate_image = $donate_image;
					if ($this->donate_type == "custom" && empty($this->donate_image))
					{
						$this->donate_type = "html";
						$errors_info = 'Due to "Donate" image problem "Donate" button was set to Standard HTML button.';
					}
					$errors = $this->check_settings();
					if (empty($errors_info) && $errors === true)
					{
						$this->update_settings();
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=donation-contest-widget&updated=true');
						die();
					}
					else
					{
						$this->update_settings();
						$message = "";
						if (is_array($errors)) $message = "The following error(s) occured:<br />- ".implode("<br />- ", $errors);
						if (!empty($errors_info)) $message .= (empty($message) ? "" : "<br />").$errors_info;
						setcookie("donationcontest_error", $message, time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=donation-contest-widget');
						die();
					}
					break;
			}
		}
	}

	function admin_warning() {
		echo '
		<div class="updated"><p><strong>Donation Contest Widget almost ready.</strong> You must do some <a href="admin.php?page=donation-contest-widget">settings</a> for it to work.</p></div>
		';
	}

	function admin_header()
	{
		global $wpdb;
		echo '
		<link rel="stylesheet" type="text/css" href="'.get_bloginfo("wpurl").'/wp-content/plugins/donation-contest-widget/css/style.css" media="screen" />
		';
	}

	function widgets_init()
	{
		register_widget('donationcontest_widget');
	}

	function front_header()
	{
		echo '
	<style type="text/css" media="screen" />
	'.$this->widget_stylesheet.'
	.dontaioncontest_contestdata {top: 0px; left: 0px;}
	</style>
	<script type="text/javascript">
		var dc_slides;
		var dc_slides_active = 0;
		jQuery(document).ready(function() {
			dc_slides = jQuery(".dontaioncontest_contestdata");
			dc_slides_active = 0;
			setTimeout("dc_switchSlide()", '.(1000*$this->slide_delay).');
		});
		function dc_switchSlide()
		{
			if (dc_slides.length > 1) {
				dc_slides_next = dc_slides_active + 1;
				if (dc_slides_next >= dc_slides.length) dc_slides_next = 0;
				jQuery(dc_slides[dc_slides_active]).fadeOut(500, function() {
					jQuery(dc_slides[dc_slides_next]).fadeIn(500, function() {
						dc_slides_active = dc_slides_next;
						setTimeout("dc_switchSlide()", '.(1000*$this->slide_delay).');
					});
				});
			}
		}
	</script>
	';
	}

	function get_top_donors($_days=0)
	{
		global $wpdb;
		if ($_days == 0) $time = 0;
		else $time = time() - $_days*3600*24;
		$sql = "SELECT * FROM ".$wpdb->prefix."dc_transactions WHERE created >= '".$time."' AND gross > 0 AND payment_status = 'Completed' ORDER BY gross DESC, created ASC LIMIT 0, ".$this->top_donors;
		$rows = $wpdb->get_results($sql, ARRAY_A);
		return $rows;
	}

}

class donationcontest_widget extends WP_Widget {

     function donationcontest_widget() {
		parent::WP_Widget(false, 'Donation Contest Widget');
     }

	function widget($args, $instance) {
		global $donationcontest;
		extract( $args );
		$title = apply_filters( 'widget_title', empty($instance['title']) ? '' : $instance['title'], $instance, $this->id_base);

		$donors = $donationcontest->get_top_donors(30);
		$content = '
		<div class="dontaioncontest_widgetbox" style="position: relative;">
		<div class="dontaioncontest_contestdata">
		<h3>Top '.$donationcontest->top_donors.' donors<br />for last 30 days</h3>
		<table border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse; width: 100%;">
		';
		for ($i=0; $i<$donationcontest->top_donors; $i++)
		{
			if ($donors[$i]['payer_name'] == "") $donors[$i]['payer_name'] = "- - - - - - - - - - - - -";
			else $donors[$i]['payer_name'] = htmlspecialchars(wordwrap($donors[$i]['payer_name'], 24, " ", 1), ENT_QUOTES);
			if (!is_numeric($donors[$i]['gross'])) $donors[$i]['gross'] = "0.00";
			$content .= "
			<tr>
			<td style='text-align: left; border: 0px solid transparent;'>".$donors[$i]['payer_name']."</td>
			<td style='text-align: right; border: 0px solid transparent;'>$".number_format($donors[$i]['gross'], 2)."</td>
			</tr>
			";
		}
		$content .= '
		</table>
		</div>';

		$donors = $donationcontest->get_top_donors(365);
		$content .= '
		<div class="dontaioncontest_contestdata" style="display: none;">
		<h3>Top '.$donationcontest->top_donors.' donors<br />for last 365 days</h3>
		<table border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse; width: 100%;">
		';
		for ($i=0; $i<$donationcontest->top_donors; $i++)
		{
			if ($donors[$i]['payer_name'] == "") $donors[$i]['payer_name'] = "- - - - - - - - - - - - -";
			else $donors[$i]['payer_name'] = htmlspecialchars(wordwrap($donors[$i]['payer_name'], 24, " ", 1), ENT_QUOTES);
			if (!is_numeric($donors[$i]['gross'])) $donors[$i]['gross'] = "0.00";
			$content .= "
			<tr>
			<td style='text-align: left; border: 0px solid transparent;'>".$donors[$i]['payer_name']."</td>
			<td style='text-align: right; border: 0px solid transparent;'>$".number_format($donors[$i]['gross'], 2)."</td>
			</tr>
			";
		}
		$content .= '
		</table>
		</div>';

		$donors = $donationcontest->get_top_donors(365);
		$content .= '
		<div class="dontaioncontest_contestdata" style="display: none;">
		<h3>Top '.$donationcontest->top_donors.' donors<br />for all the time</h3>
		<table border="0" cellspacing="0" cellpadding="0" style="border-collapse:collapse; width: 100%;">
		';
		for ($i=0; $i<$donationcontest->top_donors; $i++)
		{
			if ($donors[$i]['payer_name'] == "") $donors[$i]['payer_name'] = "- - - - - - - - - - - - -";
			else $donors[$i]['payer_name'] = htmlspecialchars(wordwrap($donors[$i]['payer_name'], 24, " ", 1), ENT_QUOTES);
			if (!is_numeric($donors[$i]['gross'])) $donors[$i]['gross'] = "0.00";
			$content .= "
			<tr>
			<td style='text-align: left; border: 0px solid transparent;'>".$donors[$i]['payer_name']."</td>
			<td style='text-align: right; border: 0px solid transparent;'>$".number_format($donors[$i]['gross'], 2)."</td>
			</tr>
			";
		}
		$content .= '
		</table>
		</div>';
		
		$content .= '
		<form name="_xclick" method="post" style="margin: 0px; padding: 0px;" action="https://www.paypal.com/cgi-bin/webscr">
			<input type="hidden" name="amount" id="amount" value="">
			<input type="hidden" name="currency_code" value="USD">
			<input type="hidden" name="cmd" value="_donations">
			<input type="hidden" name="rm" value="2">
			<input type="hidden" name="business" value="'.$donationcontest->paypal_id.'">
			<input type="hidden" name="item_number" value="donation">
			<input type="hidden" name="item_name" value="'.get_bloginfo("name").'">
			<input type="hidden" name="notify_url" value="'.get_bloginfo("wpurl").'/wp-content/plugins/donation-contest-widget/paypal_ipn.php">
			<input type="hidden" name="return" value="http://'.$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"].'">
			<input type="hidden" name="cancel_return" value="http://'.$_SERVER["HTTP_HOST"].$_SERVER["REQUEST_URI"].'">
			<input type="hidden" name="no_note" value="1">
			<input type="hidden" name="no_shipping" value="1">
			';
		if ($donationcontest->donate_type == "custom") $content .= '<input class="dontaioncontest_button" type="image" src="'.get_bloginfo("wpurl").'/wp-content/plugins/donation-contest-widget/uploads/'.rawurlencode($donationcontest->donate_image).'" border="0" name="submit" alt="">';
		else if ($donationcontest->donate_type == "paypal") $content .= '<input class="dontaioncontest_button" type="image" src="'.get_bloginfo("wpurl").'/wp-content/plugins/donation-contest-widget/images/btn_donate_LG.gif" border="0" name="submit" alt="">';
		else $content .= '<input class="dontaioncontest_button" type="submit" value="Donate">';
		$content .= '
		</form>
		</div>
		';
		if (!empty($title) || !empty($content))
		{
			echo $before_widget;
			if (!empty($title)) echo $before_title.$title.$after_title;
			echo $content;
			echo $after_widget;
		}
	}

	function update($new_instance, $old_instance) {
		$instance = $old_instance;
		$instance['title'] = strip_tags( $new_instance['title'] );
		return $instance;
	}

	function form($instance) {
		$instance = wp_parse_args((array)$instance, array('title' => ''));
		$title = strip_tags($instance['title']);
		echo '
		<p>
			<label for="'.$this->get_field_id("title").'">'._e('Title:').'</label>
			<input class="widefat" type="text" id="'.$this->get_field_id('title').'" name="'.$this->get_field_name('title').'" value="'.esc_attr($title).'" />
		</p>
		<p style="text-align: center;">Customize widget settings <a href="'.get_bloginfo('wpurl').'/wp-admin/admin.php?page=donation-contest-widget">here</a>.</p>';
	}
}

$donationcontest = new donationcontest_class();

?>