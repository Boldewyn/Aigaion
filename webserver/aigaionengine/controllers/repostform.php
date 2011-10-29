<?php  if (!defined('BASEPATH')) exit('No direct script access allowed');

class Repostform extends Controller {

	function Repostform()
	{
		parent::Controller();	
	}
	
	/** This controller allows one to repost a form that failed because the user was logged out. */
	function index()
	{
	    if (!$this->latesession->get('FORMREPOST')) {
	        appendMessage(__('No form data to repost').'.<br/>');
	        redirect('');
	    }

        //set header data
        $header ['title']       = __('Repost form');
        $header ['javascripts']       = array('tree.js','prototype.js','scriptaculous.js','builder.js');
        
        //get output
        $output  = $this->load->view('header',        $header,  true);
        $output .= $this->load->view('repostform',          array(),    true);
        $output .= $this->load->view('footer',        '',       true);
        
        //set output
        $this->output->set_output($output);
	}
	
}
?>