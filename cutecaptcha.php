<?php
/*
Plugin Name: Cute Captcha
Plugin URI: http://www.arcweb.ro
Version: v1.00
Author: <a href="http://www.arcweb.ro/">Danny</a>
Description: A cute Captcha plugin for WordPress comments.
*/

/* TODO important
   This plugin is not planned for cached pages, due to using a session variable to validate comments.
   It is possible to set a custom key in $cute_captcha_options['cached'] to enable the use with cached pages
   and/or disable session use, however the protection is not as good it will only prevent most of the bots to post.
*/

/* The path where the images are. */
$cute_captcha_path = '/wp-content/plugins/cutecaptcha/images/';

$cute_captcha_images = array(
    'elephant' => array('path' => '1.gif',
        'messages' => array('The elephant looks down at you.')
    ),
    'lion' => array('path' => '2.gif',
        'messages' => array('Choose the lion to win.', 'Really big pussycat.')
    ),

    'monkey' => array('path' => '3.gif',
        'messages' => array('Who is on a skateboard?', 'Hit the monkey!')
    ),
    'car' => array('path' => '4.gif',
        'messages' => array('The car will be the best choice for you.', 'Nice green and 4 wheels.')
    ),
    'tent' => array('path' => '5.gif',
        'messages' => array('I wonder what is in the yellow tent.')
    )


);


/* Most of the options are not used atm, just in plan to be used */

$cute_captcha_options = array(

    /****************************************************************
      cached => ''  - Set to empty string to use session keys and for pages that are not cached.
      cached => 'mycustomstring'  - Any string will disable session use and also captcha will work on cached pages.

    */
       'cached' => '',
    /*****************************************************************/


    'banIp' => 1, // 0 disabled, 1 enabled -not used
    'banPeriod' => 0, // period in days, 0 = forever - not used
    'mistakes' => 2, // how many tries before setting a ban on the IP - not used
    'imagesNumber' => 5, // how many images will be shown from the total - not used
    'hide_register' => 1, // hide captcha for registered members even if cute
    'comments_per_hour' => 0 // how many comments per hour, per ip, 0 unlimited - not used
);

if (!class_exists("Cute_Captcha_Plugin")) {
    class Cute_Captcha_Plugin
    {
        private $key;

        function Cute_Captcha_Plugin()
        {
            global $_SESSION, $cute_captcha_options;
            if(empty($cute_captcha_options['cached']))
                if (!session_id())
                          session_start();
        }


        function comment_form()
        {
            global $cute_captcha_options, $cute_captcha_images, $post, $cute_captcha_path;

            if (is_user_logged_in() && 1 == $cute_captcha_options['hide_register']) {
                return true;
            }

            /***************only for session*********************
             * Makes a new key on each post view, if same post will be opened in multiple tabs only the last tab will be
             * able to post a comment, all other tabs will have to hit back after submit, page will reload and will be
             * able to post but this is not usual user behaviour so i'm gonna live with it.
             * This prevents saving of a  pair of keys and making comments on a post using them.
             ****************************************/
            $this->setCuteCaptchaKey();


            /*
            TODO
            Put images into the buttons.css.php as raw data maybe. Anyway this will not prevent the detection of
            image data - messages association unless we alter the image data using a variable watermark for each process.
            Possible but really not needed atm!
            */
            echo "<style>";
            include('css/buttons.css');
            echo "</style>";


            /* Add encrypted photo name into a hidden field.*/
            $label = array_rand($cute_captcha_images);
            $encryptedLabel = $this->encryptLabel($label, $this->key);
            echo "<p><input type=hidden name=hiddenField value=\"" . $encryptedLabel . "\">";
            echo "<div>" . $cute_captcha_images[$label]['messages'][rand(0, count($cute_captcha_images[$label]['messages']) - 1)] . "</div>";

            /* Generate a couple of submit buttons with different values. */
            echo ' <input name="submit" type="submit" title="Submit" value="Submit" class="whiteButton" />';
            foreach ($cute_captcha_images as $pLabel => $value) {

                echo ' <input name="submit" type="submit" title="Submit" value="' . md5($this->key . $pLabel . $post->ID) .
                    '" class="colorButton" style="background: transparent url(' .
                    $cute_captcha_path.$value['path'] .
                    ') no-repeat scroll 0 0;"/>';
            }
            echo ' <input name="submit" type="submit" title="Submit" value="Submit" class="whiteButton" />';
            echo '<br /><a href="http://www.arcweb.ro/cutecaptcha" style="font-size: 10px;" target=_blank>CuteCaptcha</a></p>';


            return true;
        }

        function comment_post($comment)
        {

            global $post, $cute_captcha_images, $cute_captcha_options;

            /* TODO
               Do the magic and check if IP is allowed to post
            */


            /* WPWall, trackback & pingback - only if you know what is all about :)
                if ( function_exists('WPWall_Widget') && isset($_POST['wpwall_comment']) ) {
                   return $comment;
                }
                if ( $comment['comment_type'] != '' && $comment['comment_type'] != 'comment' ) {
                   return $comment;
                }
            */


            /* Do not use captcha for logged in users and admin comments. */
            if (is_user_logged_in() && 1 == $cute_captcha_options['hide_register']) {
                return $comment;
            }

            if (isset($_POST['action']) && $_POST['action'] == 'replyto-comment' &&
                (check_ajax_referer('replyto-comment', '_ajax_nonce', false) || check_ajax_referer('replyto-comment', '_ajax_nonce-replyto-comment', false))
            ) {
                return $comment;
            }

            /* Submit but not by clicking one of the images. Includes pressing Enter in text fields.*/
            if (empty($_POST['submit']) || $_POST['submit'] == '' || $_POST['submit'] == 'Submit') {
                wp_die('Something went wrong with your post. Try again.');
            }

            /* User has not session (not accepting cookies or is a bot). */
            $this->key = $this->getCuteCaptchaKey();
            if (empty($this->key)) {
                wp_die('Something went wrong with your session. You cannot post comments.');
            }

            /* If session key for this post changed force user to reload the page with a new key. */
            $label = $this->decryptLabel($_POST['hiddenField'], $this->key);
            if (!array_key_exists($label, $cute_captcha_images)) {
                wp_die('Session data changed for this post, please hit back and try again.');
            }


            /* Finally check if the right image has been used to submit the form. */
            $hash = md5($this->key . $label . $post->ID);
            if ($hash != $_POST['submit']) {
                /*
                 * TODO
                 * Do the magic and update wrong hit for this IP
                */

                wp_die('Wrong choice! Hit back and choose carefully, or your IP will be blocked.');


            }

            /* Comment went trough, Set new key in session */
            $this->setCuteCaptchaKey();
            return $comment;

        }


        function setCuteCaptchaKey()
        {
            global $_SESSION, $post, $cute_captcha_options;

            if(!empty($cute_captcha_options['cached']))
            {
                  $this->key = md5($cute_captcha_options['cached']);
            }
            else {
                    mt_srand(microtime(true) * 100000 + memory_get_usage(true));
                    $this->key = md5(uniqid(mt_rand(), true));
                    $_SESSION['cute_captcha_key'][$post->ID] = $this->key;
            }
        }


        function getCuteCaptchaKey()
        {
            global $_SESSION, $post, $cute_captcha_options;

            if(!empty($cute_captcha_options['cached']))
            {
                return md5($cute_captcha_options['cached']);
            }
            else
                return $_SESSION['cute_captcha_key'][$post->ID];


        }

        function encryptLabel($label, $key)
        {
            $s = strtr(base64_encode(mcrypt_encrypt(MCRYPT_RIJNDAEL_256, $key, $label, MCRYPT_MODE_CBC, md5($key))), '+/=', '-_,');
            return $s;

        }

        function decryptLabel($label, $key)
        {
            $s = rtrim(mcrypt_decrypt(MCRYPT_RIJNDAEL_256, $key, base64_decode(strtr($label, '-_,', '+/=')), MCRYPT_MODE_CBC, md5($key)), "\0");
            return $s;
        }

    }

}

if (class_exists("Cute_Captcha_Plugin")) {
    $cute_captcha_plugin = new Cute_Captcha_Plugin();

}

if (isset($cute_captcha_plugin)) {
    add_action('comment_form', array(&$cute_captcha_plugin, 'comment_form'), 1);
    add_filter('preprocess_comment', array(&$cute_captcha_plugin, 'comment_post'), 1);
}



