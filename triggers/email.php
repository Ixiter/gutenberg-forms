<?php
    require_once plugin_dir_path( __DIR__ ) . 'triggers/validator.php';
    require_once plugin_dir_path( __DIR__ ) . 'triggers/functions.php';


    require_once plugin_dir_path( __DIR__ ) . 'submissions/entries.php';

    require_once plugin_dir_path( __DIR__  ) . 'Utils/Bucket.php';

/**
 * @property Validator validator
 * @property wp_post_content post_content
 * @property array attachments
 */

class Email {

    public function __construct($post_content) {

        $this->validator = new Validator();
        $this->post_content = $post_content;
        $this->attachments = array();

    }

    public function is_fields_valid( $f ) {

        $len = count($f);

        if ( $len === 0 ) {
            return false;
        } else {
            $v = true;

            foreach ( $f as $field_id => $field_value ) {

                if ( !$field_value[ 'is_valid' ] ) {
                    $v = false;
                    break;
                } else continue;

            }

            return $v;
        }
    }


    private function get_templates($id, $blocks = null) {

        if (is_null($blocks)) {
            $blocks = $this->post_content;
        }

        $templates = array();

        foreach( $blocks as $f => $block ) {
            if ( $block['blockName'] === "cwp/block-gutenberg-forms" && $block['attrs']['id'] === $id ) {

                $decoded_template = array();

                $attributes = $block['attrs'];

                if (array_key_exists('recaptcha' , $attributes)) {
                    $decoded_template['recaptcha'] = $attributes['recaptcha'];
                }


                if (array_key_exists('template' , $attributes)) {
                    $decoded_template[] = json_decode($attributes['template'], JSON_PRETTY_PRINT);
                } else {

                    $decoded_template[] = array(
                        'subject' => "",
                        'body'    => ""
                    );
                }

                if (array_key_exists('email' ,$attributes)) {
                    $user_email = $attributes['email'];


                    if ($this->validator->is_valid_admin_mail($user_email)) {
                        $decoded_template['email'] = $user_email;
                    }
                }
                if (array_key_exists('fromEmail' ,$attributes)) {
                    $from_email = $attributes['fromEmail'];

                    $decoded_template['fromEmail'] = $from_email;
                } else {
                    $decoded_template['fromEmail'] = "";
                }

                if (array_key_exists('successType' , $attributes)) {
                    $decoded_template['successType'] = $attributes['successType'];
                } else {
                    $decoded_template['successType'] = "message";
                }

                if (array_key_exists('successURL' , $attributes)) {
                    $decoded_template['successURL'] = $attributes['successURL'];
                } else {
                    $decoded_template['successURL'] = "";
                }

                if (array_key_exists('successMessage' , $attributes)) {
                    $decoded_template['successMessage'] = $attributes['successMessage'];
                } else {
                    $decoded_template['successMessage'] = "The form has been submitted Successfully!";
                }

                if (array_key_exists('hideFormOnSuccess' , $attributes)) {
                    $decoded_template['hideFormOnSuccess'] = $attributes['hideFormOnSuccess'];
                } else {
                    $decoded_template['hideFormOnSuccess'] = false;
                }

                if (array_key_exists('saveToEntries' , $attributes)) {
                    $decoded_template['saveToEntries'] = $attributes['saveToEntries'];
                } else {
                    $decoded_template['saveToEntries'] = true;
                }

                if (array_key_exists('sendEmail', $attributes)) {
                    $decoded_template['sendEmail'] = $attributes['sendEmail'];
                } else {
                    $decoded_template['sendEmail'] = true;
                }

                $templates[] = $decoded_template;

            }else {
                $templates += $this->get_templates($id, $block['innerBlocks']);
            }

        }

        return $templates;
    }

    private function has_captcha($post){
        if (array_key_exists('g-recaptcha-response' , $post)) {
            return true;
        } else return false;
    }

    private function execute_captchas($user_response , $secretKey) {

        if ($secretKey === "") {
            return false;
        }
        if ($user_response === "") {
            return false;
        }


        $verifyResponse = file_get_contents('https://www.google.com/recaptcha/api/siteverify?secret='.$secretKey.'&response='.$user_response);

        $response = json_decode($verifyResponse, true);

        if (array_key_exists('success' , $response) ) {
            return $response['success'];
        }

        return false;
    }

    public function init() {

        $arranged_fields = array();

        $post = $_POST;

        $post_without_submit = array_remove_keys($_POST,['submit']);

        if (count($_FILES) !== 0) {
            foreach ($_FILES as $file_id => $file_meta) {
                if (!empty($file_meta['tmp_name'])) {
                    $post_without_submit[$file_id] = $file_meta;
                }
            }
        }

        foreach ( $post_without_submit as $field_id => $field_value ) {
            $exploded_id = explode( "__", $field_id );

            $field_type = end( $exploded_id ); //type of th e field i.e email,name etc;


            $f_DECODED = $this->validator->decode( $field_type );


            $type = array_key_exists('type' , $this->validator->decode( $field_type )) ? $this->validator->decode( $field_type )['type'] : "";

            $is_valid = $this->validator->validate( $type, $field_value, $field_type );

            $id = end($f_DECODED);

            $sanitizedValue = $this->validator->sanitizedValue($type, $field_value);

            $sanitized_field_value = NULL;

            if (is_array($field_value)) {
                $sanitized_field_value = join("," , $field_value);
            } else if ( $id === 'upload' ) {
                $sanitized_field_value = $field_value;
            } else {
                $sanitized_field_value = $sanitizedValue;
            }

            $arranged_data = array(
                'field_data_id' => $id,
                'field_value' => $sanitized_field_value,
                'is_valid'    => $field_id === "g-recaptcha-response" ? true: $is_valid,
                'field_id'    => $field_id,
                'field_type'  =>  $type,
                'decoded_entry' =>  $this->validator->decode( $field_type )
            );

            if ($type === 'file_upload') {

                // updating attachment files;
                $file_to_upload = $_FILES;
                $file_name = $file_to_upload[$field_id]['name'];
                $tmp_name = $file_to_upload[$field_id]['tmp_name'];

                $parsed_alloweds =  json_decode($f_DECODED['extra_meta'], false);

                $ext = pathinfo($file_name, PATHINFO_EXTENSION);

                $is_allowed = $this->validator->test_file_formats($ext, $parsed_alloweds);

                

                if( $is_allowed ) {
                    
                    $created_file = Bucket::upload( $tmp_name, $ext );

                    $arranged_data['file_name'] = $created_file['filename'];

                    $this->attachments[] = $created_file['path'];

                } else {
                    $arranged_data['is_valid'] = false;
                }

            }

            if ( $this->validator->is_hidden_data_field($field_id) ) {

                $arranged_data['is_valid'] = true;
            }

            $arranged_fields[] = $arranged_data;
        }


        if ( $this->is_fields_valid( $arranged_fields ) ) {
            // check if all the fields are valid;
            $this->sendMail( $arranged_fields );
        }

    }


    private function with_fields( $fields, $target ) {

        $result = $target;
        $data = array();

        foreach( $fields as $field => $field_value ) {

            $field_name = "{{".$field_value['field_type']."-".$field_value['field_data_id']."}}";
            
            if ($field_name !== "{{-}}") {
                $data[$field_name] = $field_value['field_value'];
            }

            $data['{{all_data}}'] = merge_fields_with_ids( $fields );

        }

        $replaced_str = strtr($target, $data);

        return $replaced_str;

    }

    private function url_success($url) {

        if ($this->validator->isURL($url)) {
            $string = '<script type="text/javascript">';
            $string .= 'window.location = "' . $url . '"';
            $string .= '</script>';

            echo $string;
        }

    }

    private function message_success( $message, $hideFormOnSuccess ) {


        $message_id = $_POST['submit'];


        $css = "#$message_id { display: block }";


        if ($hideFormOnSuccess === true) {
            $css .= "\n [data-formid=".$message_id."] { display: none; }";
        }

        $hidden_style = "<style> $css </style>";


        echo $hidden_style;

    }

    private function attempt_success( $template ) {

        /**
         * @var string $successType
         * @var string $successURL
         * @var string $successMessage
         * @var boolean $hideFormOnSuccess
         */

        if (!isset($template)) return;
        extract($template);

        if ( $successType === "url" ) {
            $this->url_success($successURL);
        } else if ($successType === "message") {
            $this->message_success( $successMessage, $hideFormOnSuccess );
        }

    }

    public function extract_from_details( $from ) {
        // the fromEmail from the backend comes at this pattern "Name, Email" ( comma separated )
        
        $details = explode( ',' , trim( $from )  );


        // checking if the from contains both
        if ( sizeof( $details ) === 2 ) {


            $email = trim($details[1]);
            $name = trim($details[0]);

            if ( ! $this->validator->isEmail( $email ) ) {
                return false;
            }
            

            return array(
                'email' => $email,
                'name'  => $name
            );

        } else {
            return false;
        }
    }

    public function sendMail( $fields ) {

        $template = $this->get_templates($_POST['submit'])[0];

        /**
         * @var string $fromEmail
         */

        isset($template) && extract($template);

        $mail_subject = $this->with_fields($fields, $template[0]['subject']);
        $mail_body = $this->with_fields($fields, $template[0]['body']);
        $headers = '';

        if ( !empty($fromEmail) and $this->validator->isEmpty( $fromEmail ) === false and $this->extract_from_details( $fromEmail ) ) {

            $from_details = $this->extract_from_details( $fromEmail );

            $from_name = $from_details['name'];
            $from_email = $from_details['email'];

            $headers .= "From: $from_name <$from_email>";
        }

        $post = $_POST;
        

        if ($this->has_captcha( $post )) {
            $captcha_success = $this->execute_captchas($post['g-recaptcha-response'], $template['recaptcha']['clientSecret']);

            if (!$captcha_success) {
                $captcha_danger = $_POST['submit']."-captcha";

                echo "<style> .cwp-danger-captcha#$captcha_danger { display:block !important } </style>";

                return;
            }
        }

        $newEntry = Entries::create_entry( $template, $mail_subject, $mail_body, $fields, $this->attachments );
        $record_entries = $template['saveToEntries'];
        $send_email = $template['sendEmail'];

        if ($send_email === true) {
            if (array_key_exists('email' , $template)) {

                if ($this->validator->isEmpty($headers)) {
                    wp_mail($template['email'],$mail_subject,$mail_body , null, $this->attachments);
                } else {
                    wp_mail($template['email'],$mail_subject,$mail_body , $headers, $this->attachments);
                }
    
                if ($record_entries) {
                    Entries::post( $newEntry );
                }
                
                $this->attempt_success($template);
    
            } else {
                if ($this->validator->isEmpty($headers)) {
                    wp_mail(get_bloginfo('admin_email'),$mail_subject,$mail_body, null, $this->attachments);
                } else {
                    wp_mail(get_bloginfo('admin_email'),$mail_subject,$mail_body , $headers , $this->attachments);
                }
                
                if ($record_entries) {
                    Entries::post( $newEntry );
                }
    
                $this->attempt_success($template);
            }
        }
    }
}
