<?php

/**
 * Licensed under the Apache License, Version 2.0 (the "License"); you may
 * not use this file except in compliance with the License. You may obtain
 * a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
 * WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
 * License for the specific language governing permissions and limitations
 * under the License.
 */

/**
 * Error Reporting switches
 * PS: Please change the error reporting level as required.
 */
if(defined("PICOVICO_DEBUG") AND PICOVICO_DEBUG === TRUE){
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
}else{
	error_reporting(0);
	session_start();
}

if (!function_exists('curl_init')) {
    throw new Exception('Picovico needs the CURL PHP extension.');
}

if (!function_exists('json_decode')) {
    throw new Exception('Picovico needs the JSON PHP extension.');
}

require_once __DIR__."/lib/exception.php";
require_once __DIR__."/lib/base.php";
require_once __DIR__."/lib/request.php";
require_once __DIR__."/lib/urls.php";
require_once __DIR__."/lib/utils.php";

/**
 * Picovico Class for end API developers
 * Handles all necessary steps related to the video definition and creation process. 
 */
class Picovico extends PicovicoBase{

    const API_VERSION = '2.0';
    const VERSION = '2.0.1';
    const API_SERVER = 'uapi-f1.picovico.com';

    /** Available Video rendering states */
    const VIDEO_INITIAL = "initial";
    const VIDEO_PUBLISHED = "published";
    const VIDEO_PROCESSING = "processing";

    /** Rendering Quality Levels */
    const Q_360P = 360; // ld
    const Q_480P = 480; // sd
    const Q_720P = 720; // md
    const Q_1080P = 1080; // hd

    const STANDARD_SLIDE_DURATION = 5;

    // Video Data for the final video request
    private $vdd = NULL;
    private $video_id = NULL;

	function __construct($config = NULL){
		parent::__construct($config);
		$this->vdd = array();
		$this->video_id = NULL;
	}

	/**
	 * Login using Picovico username and password
	 * If logged in successfully, login tokens are set.
	 * @param $username
	 * @param $password
	 */
	function login($username, $password){

		$params = array('username'=>$username,'password'=>$password, 'device_id'=>$this->generated_device_id());

		$response = $this->request->make_request(PicovicoUrl::login, $params, NULL, PicovicoRequest::POST, PicovicoRequest::ANONYMOUS);

		if(isset($response['access_key']) AND isset($response['access_token'])){
			$this->set_login_tokens($response['access_key'], $response['access_token']);
		}

		return $response;
	}

	/**
	 * If any saved access_key and access_token available, continue with those available tokens
	 * @param $access_key
	 * @param $access_token
	 */
	function set_login_tokens($access_key, $access_token){
		$this->request->set_tokens($access_key, $access_token);
	}

	/**
	 * Open any existing project which has not yet been rendered
	 * @param $video_id
	 */
	function open($video_id = NULL){
		$this->video_id = NULL;
		$this->vdd = array();
		if($video_id != NULL){
			$picovico_video = $this->get_video($video_id);
			if($picovico_video['status'] === Picovico::VIDEO_INITIAL){
				$this->video_id = $video_id;
				$this->vdd =  $picovico_video;
			}
		}
		return $this->video_id;
	}

	/**
	 * Begin with an empty project.
	 * @param $name
	 * @param $quality - defaults to 360p
	 */
	function begin($name, $quality = Picovico::Q_360P){
		$this->video_id = NULL;
		$this->vdd = array();
		$params = array('name'=>$name, 'quality'=>$quality);
		$response = $this->request->post(PicovicoUrl::begin_project, $params);
		if($response['id']){
			$this->video_id = $response['id'];
			$this->vdd = $response;
			// truncate assets if defined already, open existing project to retain
			$this->vdd["assets"] = array();
		}
		return $this->video_id;
	}

	/**
	 * Upload local image file or any remote image to the logged in account.
	 */
	function upload_image($image_path, $source = NULL){
		return parent::upload_image($image_path, $source);
	}

	/**
	 * Upload local music file or any remote music to the logged in account. 
	 */
	function upload_music($music_path, $source = NULL){
		return parent::upload_music($music_path, $source);
	}

	/**
	 * Upload and append any image. Remote contents aren't downloaded locally.
	 * @param $image_path
	 * @param $caption
	 */
	function add_image($image_path, $caption = "", $source = "hosted"){
		$image_response = $this->upload_image($image_path, $source);
		if(isset($image_response["id"])){
			$this->add_library_image($image_response["id"], $caption);
		}
	}

	/**
	 * Append any image previously uploaded
	 */
	function add_library_image($image_id, $caption = ""){
		if($image_id){
			PicovicoBase::append_image_slide($this->vdd, $image_id, $caption);
		}
	}

	/**
	 * Append text slide to the project
	 */
	function add_text($title = "", $text = ""){
		if($title OR $text){
			PicovicoBase::append_text_slide($this->vdd, $title, $text);	
		}
	}

	/** 
	 * Define the backgroudn music
	 */
	function add_music($music_path){
		$music_response = $this->upload_music($music_path);
		if(isset($music_response["id"])){
			$this->add_library_music($music_response["id"]);
		}
	}

	/* 
	 * Define any previously uploaded music, or any music available from library. 
	 */
	function add_library_music($music_id){
		PicovicoBase::set_music($this->vdd, $music_id);
	}

	/**
	 * Fetches styles available for the logged in account
	 */
	function get_styles(){
		$url = sprintf(PicovicoUrl::get_styles);
		return $this->request->get($url, NULL, NULL, PicovicoRequest::GET, PicovicoRequest::AUTHORIZED);
	}

	/**
	 * Defines style for the current video project
	 */
	function set_style($style_machine_name){
		$this->vdd["style"] = $style_machine_name;
	}

	/*
	 * Defines rendering quality for the current video project
	 */
	function set_quality($quality){
		$this->vdd["quality"] = intval($quality);
	}

	/**
	 * Append credit slides
	 */
	function add_credits($title = null, $text = null){
		if($title or $text){
			if(!isset($this->vdd["credit"])){
				$this->vdd["credit"] = array();
			}
			$this->vdd["credit"][] = array($title, $text);
		}
	}

	/**
	 * Clear all credit slides
	 */
	function remove_credits(){
		$this->vdd["credit"] = array();
	}

	/**
	 * Fetch any existing video. Use open() for editing.
	 */
	function get($video_id){
		$url = sprintf(PicovicoUrl::single_video, $video_id);
		return $this->request->get($url);
	}

	function save(){
		if(!$this->video_id){
			return NULL;
		}
		// fix music first
		parent::append_music($this->vdd);
		$url = sprintf(PicovicoUrl::save_video, $this->video_id);
		return $this->request->post($url, $this->vdd);
	}

	function create(){
		$response = $this->save();
		$url = sprintf(PicovicoUrl::create_video, $this->video_id);
		return $this->request->post($url);
	}
}

