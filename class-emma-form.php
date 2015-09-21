<?php 

include_once( 'admin/class-account-information.php' );
include_once( 'admin/class-form-setup.php' );

class Emma_Form {

    private $form_setup_settings = array();
    private $account_information_settings = array();

    public $status_txt;
    public $emma_response;
	    /* 
		   Code   |	 Meaning
		   800	  |  Success: member validated/added
	       810	  |	 Error: invalid email
	       820	  |  Error: wordpress error
	       830	  |  Error: member failed
		
		*/
    private $emma_api;
    
    public $signup_form_id;
    
    public $output_the_form;

    function __construct () {

		// setup our AJAX actions
        //add_action( 'wp_ajax_emma_ajax_form_submit', array( $this, 'emma_ajax_form_submit_callback' ) );
        //add_action( 'wp_ajax_nopriv_emma_ajax_form_submit', array( $this, 'emma_ajax_form_submit_callback' ) );
		
        // get authorization data from plugin options
        $this->account_information_settings = (array) get_option( Account_Information::$key );
        $this->form_setup_settings = (array) get_option( Form_Setup::$key );
        
        $this->output_the_form = true;
		
        // instantiate new emma api class, pass in auth data
        $this->emma_api = new Emma_API( $this->account_information_settings['account_id'], $this->account_information_settings['publicAPIkey'], $this->account_information_settings['privateAPIkey'], $this->account_information_settings['form_signup_id'] );

    }

    // outputs the emma form,
    public function generate_form( $ajax_data = array() ) {
		
		// if we have $ajax_data, then let's set it all to $_POST
		if ( !empty($ajax_data) ) {
			$_POST['emma_form_submit'] = 'yep';
			$_POST['emma_email'] = $ajax_data['emma_email'];
			if(isset($ajax_data['emma_firstname'])) $_POST['emma_firstname'] = $ajax_data['emma_firstname'];
			if(isset($ajax_data['emma_lastname'])) $_POST['emma_lastname'] = $ajax_data['emma_lastname'];
			$_POST['emma_signup_form_id'] = $ajax_data['emma_signup_form_id'];
		}
		
        // check if the form has been submitted
        if ( isset($_POST['emma_form_submit']) ) {

            // validate form fields, if not valid, return status_txt
            if ( !is_email($_POST['emma_email']) ) {

                $this->status_txt = $this->form_setup_settings['email_validation_status_txt'];
                $this->emma_response = '810';

            // process the form
            } else {

                // get data from form into transportable array
                $data = $this->process_form_data();

                // put members in appropriate group(s)
                $data = $this->assign_members_to_groups( $data );
				
				//echo '<pre>' . print_r(json_encode($data), true) . '</pre>';
				
                // call emma, import_single_member, pass in data
                $response = $this->emma_api->import_single_member( $data );

                // handle the response,
                // pass in wp_error or returned array from emma
                // get back object w/ status
                $handled_response = $this->emma_request_response_handler( $response );
                
                //echo '<pre>' . print_r($handled_response, true) . '</pre>';

                // check to see if the member was added,
                if ( $handled_response->status == 'member_added' ) {
                    // verify the member was added
                    $verified_member = $this->emma_verify_member( $handled_response );

                    // member successfully added
                    if ( $verified_member->status == 'member_verified' ) {

                        // get custom confirmation message, pass through to form
                        $this->status_txt = $this->form_setup_settings['confirmation_msg'];
						$this->emma_response = '800';
                    }

                    // if a wp_error comes back, pass it through to the status text
                    if ( $verified_member->status == 'wp_error' ) {
                        $this->status_txt = $verified_member->wp_error;
                        $this->emma_response = '820';
                    }

                }
                
                if ( $handled_response->status == 'member_failed' ) {
                    $this->status_txt = $this->form_setup_settings['member_failed_status_txt'];
                    $this->emma_response = '830';
                }
                
                $this->raw_data = $data;
                $this->raw_response = $response;

            }

        }
		
		if ($this->output_the_form === true) {
			return $this->output_form();
		}

    }

	/*
function emma_ajax_form_submit_callback() {
		ob_clean();
		echo print_r($_POST, true);
		
		wp_die();
	}
*/
	
    public function process_form_data() {
	    
        // construct data array to send to emma, array structure parallels emma api data request object
        $form_data = array();
        $form_data['email'] = $_POST['emma_email'];
        if ( isset($_POST['emma_firstname']) ) $form_data['fields']['first_name'] = $form_data['fields']['name_first'] = $_POST['emma_firstname'];
        if ( isset($_POST['emma_lastname']) ) $form_data['fields']['last_name'] = $form_data['fields']['name_last'] = $_POST['emma_lastname'];
        if ( isset($_POST['emma_signup_form_id']) ) $form_data['signup_form_id'] = $_POST['emma_signup_form_id'];

        return $form_data;
    }

    public function assign_members_to_groups( $data ) {

        // assign members to group(s), based on settings
        if ( $this->account_information_settings['group_active'] !== '0' ) {

            // the api accepts an array of integers.
            // pass in the active group id as an integer.
            $group_ids = (int)$this->account_information_settings['group_active'];

            // for now, we're just passing in one group,
            $data['group_ids'] = array($group_ids);

        }

        // if they're not assigning any members to groups, just pass it on thru
        return $data;
    }

    // handles requests returned from the Emma_API class, has to deal w/ WP_Error as well as return objects
    public function emma_request_response_handler( $response ) {
	    
        // if the API call returns an array
        if ( is_array($response) ) {

            // decode the JSON from the request body
            $response_body = json_decode( $response['body'] );

            $response_object = new stdClass();

            // convert to object
            foreach ( $response as $key => $value ) {
                $response_object->$key = $value;
            }
            // put the body back
            $response_object->body = $response_body;

            $response = $response_object;

            // check if the member was added
            if ( $response_body->status == 'a' ) {
                $response->status = 'member_added';
            } else if ( $response_body->status == 'e' ) {
                $response->status = 'member_not_added';
            } else {
                $response->status = 'member_fail';
            }

        }

        // check if the response is a wp_error
        if( is_wp_error( $response ) ) {

            $response->status = 'wp_error';
            $response->wp_error = 'Something went wrong! Please try to submit the form again,';
            // get the Wordpress error
            $response->wp_error .= '<pre>' . $response->get_error_message() . '</pre>';

        }

        return $response;
    }

    public function emma_verify_member( $handled_response ) {

        // call get_member_detail to verify the member was added, using their member ID
        $verified_member = $this->emma_api->get_member_detail( $handled_response->body->member_id );

        if( is_wp_error( $verified_member ) ) {

            $verified_member->status = 'wp_error';
            $verified_member->wp_error =  'Something went wrong! Please try to submit the form again,';
            // get the Wordpress error
            $verified_member->wp_error .= '<pre>' . $verified_member->get_error_message() . '</pre>';

        } else {

            $verified_member_body = json_decode( $verified_member['body'] );

            $verified_member_object = new stdClass();

            // convert to object
            foreach ( $verified_member as $key => $value ) {
                $verified_member_object->$key = $value;
            }
            // put the body back
            $verified_member_object->body = $verified_member_body;
            $verified_member = $verified_member_object;
            $verified_member->status = 'member_verified';

        }

        return $verified_member;
    }
    
    public function strposa($haystack, $needle, $offset=0) {
	    if(!is_array($needle)) $needle = array($needle);
	    foreach($needle as $query) {
	        if(strpos($haystack, $query, $offset) !== false) return true; // stop on first true result
	    }
	    return false;
	}

    public function output_form() {
		
		//enqueue our front-end js
		wp_enqueue_script('emma js');
		
		if ($this->signup_form_id != '') {
			$signup_form_id = $this->signup_form_id;
		} else {
			$signup_form_id = $this->form_setup_settings['form_signup_id'];
		}
		
		$form_style_options = get_option('emma_form_custom');
		
		if ($this->signup_form_layout != '') {
			$form_layout = $this->signup_form_layout;
		} else {
			$form_layout = $form_style_options['form_layout_select'];
		}
		
		// Do some validation of user's form width
		if ($this->form_setup_settings['form_size'] == '') {
			// If they haven't set one, set the form width to 100%;
			$form_width = '100%';
		} else {
			$user_set_width = $this->form_setup_settings['form_size'];
			
			// See if the user used a unit for their value
			// If not, then set their value to a pixel width
			if ( $this->strposa($user_set_width, array('px','%')) ) {
				$user_set_width = $user_set_width;
			} else {
				// If they used any characters EXCEPT for 'px' or '%'
				// convert it to an integer pixel value
				$user_set_width = intval($user_set_width) . 'px';
			}
			$form_width = $user_set_width;
		}
		
        // check if they want to include the first and last name fields
        // and include custom placeholder text
        $emma_form_name_fields = '';
        switch( $this->form_setup_settings['include_firstname_lastname'] ) {
	        case 'first':
	        	$emma_form_name_fields .= '<li class="emma-form-row emma-cf">';
	            $emma_form_name_fields .= '<label class="emma-form-label" for="emma-firstname">First Name</label>';
	            $emma_form_name_fields .= '<input id="emma-firstname" class="emma-form-input" type="text" name="emma_firstname" size="30" placeholder="' . $this->form_setup_settings['firstname_placeholder'] . '">';
	            $emma_form_name_fields .= '</li>';
	        	break;
	        case 'both':
	        	$emma_form_name_fields .= '<li class="emma-form-row emma-cf">';
	            $emma_form_name_fields .= '<label class="emma-form-label" for="emma-firstname">First Name</label>';
	            $emma_form_name_fields .= '<input id="emma-firstname" class="emma-form-input" type="text" name="emma_firstname" size="30" placeholder="' . $this->form_setup_settings['firstname_placeholder'] . '">';
	            $emma_form_name_fields .= '</li>';
	            $emma_form_name_fields .= '<li class="emma-form-row emma-cf">';
	            $emma_form_name_fields .= '<label class="emma-form-label" for="emma-lastname">Last Name</label>';
	            $emma_form_name_fields .= '<input id="emma-lastname"  class="emma-form-input" type="text" name="emma_lastname"  size="30" placeholder="' . $this->form_setup_settings['lastname_placeholder'] . '">';
	            $emma_form_name_fields .= '</li>';
	        	break;
	        default: // Catches case 'none'
	        	// We don't have anything else to add
	        	$only_email = true;
	        	break;
        }
        
        if ( $only_email ) {
	        $form_fields_class = 'emma-only-email';
        } else {
	        $form_fields_class = '';
        }
        
        $form_unique = mt_rand(1000000,9999999);
        // output form markup
        $emma_form = '<div id="emma-form" class="emma-' . $form_layout . '-layout ' . $form_fields_class . '" style="width:' . $form_width . '">';
        $emma_form .= '<div class="emma-wrap">';
        $emma_form .= '<form id="emma-subscription-form" data-form-unique="' . $form_unique . '" action="' . htmlspecialchars( $_SERVER['REQUEST_URI'] ) . '" method="post" accept-charset="utf-8">';
        $emma_form .= '<ul id="emma-form-elements" class="emma-cf">';
        $emma_form .= '<li class="emma-form-row emma-cf">';
        $emma_form .= '<label class="emma-form-label" for="emma-email"> Email</label>';
        $emma_form .= '<input id="emma-email" class="emma-form-input" type="text" name="emma_email" size="30" placeholder="' . $this->form_setup_settings['email_placeholder'] . '">';
        $emma_form .= '</li>';
        
        $emma_form .= $emma_form_name_fields;

        $emma_form .= '<li class="emma-form-row emma-form-row-last">';
        $emma_form .= '<span class="emma-form-label-required"></span>';
        
        $emma_form .= '<input type="hidden" name="emma_signup_form_id" value="' . $this->account_information_settings['form_signup_id'] . '" />';
        $emma_form .= '<input type="hidden" name="emma_form_unique" value="' . $form_unique . '" />';
        
        $emma_form .= '<input id="emma-form-submit" type="submit" name="emma_form_submit" value="' . $this->form_setup_settings['submit_txt'] . '">';
        
        $emma_form .= '</li>';
        $emma_form .= '</ul>';
        $emma_form .= '</form>';
        
        //$emma_form .= '<pre>'.print_r($this->account_information_settings, true).'</pre>';

        // output status message
        if ( isset($_POST['emma_form_submit']) ) {
            $emma_form .= '<div class="emma-status">' . $this->status_txt . '</div>';
        }

        $emma_form .= '</div><!-- end .emma-wrap -->';

        $emma_form .= '</div><!-- end #emma-form -->';

        return $emma_form;

    } // end output_form

} // end Class Emma_Form

