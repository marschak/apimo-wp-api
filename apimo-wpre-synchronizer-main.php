<?php

// Includes the core classes
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
require_once(ABSPATH . 'wp-admin/includes/plugin.php');
if (!class_exists('WP_Http')) {
  require_once(ABSPATH . WPINC . '/class-http.php');
}

class ApimoWPRESynchronizer
{
  /**
   * Instance of this class
   *
   * @var ApimoWPRESynchronizer
   */
  private static $instance;

  /**
   * @var string
   */
  private $siteLanguage;

  /**
   * Constructor
   *
   * Initializes the plugin so that the synchronization begins automatically every hour,
   * when a visitor comes to the website
   */
  public function __construct()
  {
    // Retrieve site language
    $this->siteLanguage = $this->getSiteLanguage();

    // Trigger the synchronizer event every hour only if the API settings have been configured
    if (is_array(get_option('apimo_WPRE_synchronizer_settings_options'))) {
      if (isset(get_option('apimo_WPRE_synchronizer_settings_options')['apimo_api_provider']) &&
        isset(get_option('apimo_WPRE_synchronizer_settings_options')['apimo_api_token']) &&
        isset(get_option('apimo_WPRE_synchronizer_settings_options')['apimo_api_agency'])
      ) {
        add_action(
          'apimo_WPRE_synchronizer_hourly_event',
          array($this, 'synchronize')
        );

        // For debug only, you can uncomment this line to trigger the event every time the blog is loaded
       // add_action('init', array($this, 'synchronize'));
      }
    }
  }

  /**
   * Retrieve site language
   */
  private function getSiteLanguage()
  {
    return substr(get_bloginfo('language'), 0, 2);
  }

  /**
   * Creates an instance of this class
   *
   * @access public
   * @return ApimoWPRESynchronizer An instance of this class
   */
  public static function getInstance()
  {
    if (null === self::$instance) {
      self::$instance = new self;
    }

    return self::$instance;
  }

  /**
   * Synchronizes Apimo and Pro Real Estate plugnins estates
   *
   * @access public
   */
  public function synchronize()
  {
    // Gets the properties
    $return = $this->callApimoAPI(
      'https://api.apimo.pro/agencies/'
      . get_option('apimo_WPRE_synchronizer_settings_options')['apimo_api_agency']
      . '/properties',
      'GET'
    );

    // Parses the JSON into an array of properties object
    $jsonBody = json_decode($return['body']);

    if (is_object($jsonBody) && isset($jsonBody->properties)) {
      $properties = $jsonBody->properties;

      if (is_array($properties)) {
        foreach ($properties as $property) {
          // Parse the property object
          $data = $this->parseJSONOutput($property);

          if (null !== $data) {
            // Creates or updates a listing
            $this->manageListingPost($data);
          }
        }

        $this->deleteOldListingPost($properties);
      }
    }
  }

  /**
   * Parses a JSON body and extracts selected values
   *
   * @access private
   * @param stdClass $property
   * @return array $data
   */
  private function parseJSONOutput($property)
  {
    $data = array(
     /* 'user' => $property->user,
      'agent_name' => $property->user->firstname,
      'agent_phone' => $property->user->mobile,
      'agent_email' => $property->user->email,
      'ahent_photo' => $property->user->picture,*/
      'updated_at' => $property->updated_at,
      'created_at' => $property->created_at,
      'reference' => $property->reference,
      'postTitle' => array(),
      'postContent' => array(),
      'images' => array(),
      //'address' => $property->address,
      'Price' => (!$property->price->value ? __('Price on ask') : $property->price->value),
      'PricePrefix' => '',
      'PricePostfix' => '',
      'SqFt' => preg_replace('#,#', '.', $property->area->value),
      //'LatLng' => ( $property->latitude && $property->longitude   ? $property->latitude . ', ' . $property->longitude : ''  ),
      'Lat' =>  $property->latitude,
      'Lng' =>  $property->longitude,

      'ExpireListing' => '',
      'ct_property_type' => $property->type,
      'categoryId' => $property->category,
      'services' => $property->services,
      'rooms' => $property->rooms,
      'beds' => 0,
      'Beds' => 0,
      'Baths' => 0,
      'status' => '',
      'City' => $property->city->name,
      'State' => '',
      'Zip' => $property->city->zipcode,
      'Country' => $property->country,
      'Community' => '',
      'Feat' => '',
    
    );

    foreach ($property->services as $service) {
      $data['service']= $service;
    
    }

    foreach ($property->comments as $comment) {
      $data['postTitle'][$comment->language] = $comment->title;
      $data['postContent'][$comment->language] = $comment->comment;
    }
    $data['rooms'] = $property->rooms;
    $data['beds'] = $property->bedrooms;

    foreach ($property->areas as $area) {
      if ($area->type == 1 ||  $area->type == 53 ||     $area->type == 70 ) 
      {
        $data['Beds'] += $area->number;
      } else if ($area->type == 8 || $area->type == 41 ||  $area->type == 13 || $area->type == 42 ) 
      {
        $data['Baths'] += $area->number;
      }
    }
    foreach ($property->regulations as $regulation) {
      if ($regulation->type == 1) {
        $data['energy1'] = $regulation->value;
         }
        }
        
    foreach ($property->regulations as $regulation) {
      if ($regulation->type == 2) {
      $data['energy2'] = $regulation->value;
        }
      }
         foreach ($property->pictures as $picture) {
      $data['images'][] = array(
        'id' => $picture->id,
        'url' => $picture->url,
        'rank' => $picture->rank
      );
    }

    return $data;
  }

  /**
   * Creates or updates a listing post
   *
   * @param array $data
   */
  private function manageListingPost($data)
  {
    // Converts the data for later use
    $postTitle = $data['postTitle'][$this->siteLanguage];
    if ($postTitle == '') {
      foreach ($data['postTitle'] as $lang => $title) {
        $postTitle = $title;
      }
    }

    $postContent = $data['postContent'][$this->siteLanguage];
    if ($postContent == '') {
      foreach ($data['postContent'] as $lang => $title) {
        $postContent = $title;
      }
    }

    $postUpdatedAt = $data['updated_at'];
    $create_date  = $data['created_at'];
   
    $images = $data['images'];
    $reference = $data['reference'];
   /* $agent_name = $data['gent_name'];
    $agent_phone =$data['agent_phone'];
    $agent_email = $data['gent_email'];
    $agent_photo = $data['agent_photo'];*/
   // $address = $data['address'];
    $ctPrice = str_replace(array('.', ','), '', $data['Price']);
   // $PricePrefix = $data['PricePrefix'];
   // $PricePostfix = $data['PricePostfix'];
    $SqFt = $data['SqFt'];
   // $VideoURL = $data['VideoURL'];
   // $MLS = $data['MLS'];
    //$LatLng = $data['LatLng'];
    $Lat = $data['Lat'];
    $Lng = $data['Lng'];
   // $ExpireListing = $data['ExpireListing'];
    $typeid = $data['ct_property_type'];
    $categoryId = $data['categoryId'];
    $servicesId = $data['services'];
    $rooms = $data['rooms'];
    $beds = $data['beds'];
   // $Beds = $data['Beds'];
   // $Baths = $data['Baths'];
    $Status = $data['status'];
    $City = $data['City'];
    $State = $data['State'];
    $Zip = $data['Zip'];
    $Country = $data['Country'];

    $energetic1 = $data['energy1'];
    $energetic2= $data['energy2'];
    //$Community = $data['Community'];
    //$Feat = $data['Feat'];
    $type_arr = array(
      '1' => 'Apartment',
      '2' => 'House',
      '3' => 'Land',
      '4' => 'Business',
      '5' => 'Garage/Parking',
      '6' => 'Building',
      '7' => 'Office',
      '8' => 'Boat',
      '9' => 'Warehouse',
      '10' => 'Cellar / Box',
       );
       $type = $type_arr[$typeid];


       
       $services_arr = array(
        '1'	=> 'Internet',
        '2'	=> 'Fireplace',
        '3'	=> 'Disabled access',
        '4'	=> 'Air-conditioning',
        '5'	=> 'Alarm system',
        '6'	=> 'Lift',
        '7'	=> 'Caretaker',
        '8'	=> 'Double glazing',
        '9'	=> 'Intercom',
        '10'	=> 'TV distribution',
        '11'	=> 'Swimming pool',
        '12'	=> 'Security door',
        '13'	=> 'Tennis court',
        '14'	=> 'Irrigation sprinkler',
        '15'	=> 'Barbecue',
        '16'	=> 'Electric gate',
        '17'	=> 'Crawl space',
        '18'	=> 'Car port',
        '19'	=> 'Caretaker house',
        '20'	=> 'Sliding windows',
        '21' => 'Central vacuum system',
        '22'	=> 'Electric shutters',
        '23'	=> 'Window shade',
        '24'	=> 'Electric awnings',
        '25'	=> 'Washing machine',
        '26'	=> 'Jacuzzi',
        '27'	=> 'Sauna',
        '28'	=> 'Whirlpool tub',
        '29'	=> 'Well',
        '30'	=> 'Spring',
        '31'	=> 'Engine generator',
        '32'	=> 'Dishwasher',
        '33'	=> 'Hob',
        '34'	=> 'Safe',
        '35'	=> 'Helipad',
        '36'	=> 'Videophone',
        '37'	=> 'Video security',
        '38'	=> 'Stove',
        '39'	=> 'Iron',
        '40'	=> 'Hair dryer',
        '41'	=> 'Television',
        '42'	=> 'DVD Player',
        '43'	=> 'CD Player',
        '44'	=> 'Outdoor lighting',
        '45'	=> 'Spa',
        '46'	=> 'Home automation',
        '47'	=> 'Furnished',
        '48'	=> 'Linens',
        '49'	=> 'Tableware',
        '50'	=> 'Clothes dryer',
        '51'	=> 'Phone',
        '52'	=> 'Refrigerator',
        '53'	=> 'Oven',
        '54'	=> 'Reception 24/7',
        '55'	=> 'Coffeemaker',
        '56'	=> 'Microwave oven',
        '57'	=> 'Shabbat elevator',
        '58'	=> 'Sukkah',
        '59'	=> 'Synagogue',
        '60'	=> 'Digicode',
        '61'	=> 'Common laundry',
        '62'	=> 'Pets allowed',
        '63'	=> 'Metal shutters',
        '64'	=> 'Wiring closet',
        '65'	=> 'Computer network',
        '66'	=> 'Dropped ceiling',
        '67'	=> 'Fire hose cabinets',
        '68'	=> 'Fire sprinkler system',
        '69'	=> 'Wharf',
        '70'	=> 'Connected thermostat',
        '71'	=> 'Bowling green',
        '72'	=> 'Water softener',
        '73'	=> 'Triple glazing',
        '74'	=> 'Well drilling',
        '75'	=> 'Optical fiber',
        '76'	=> 'Non-flooding',
        '77'	=> 'Backup water system',
        '78'	=> 'Water filtration system',
        '79'	=> 'Air filtration system',
        '80'	=> 'Fire alarm system',
        '81'	=> 'Commercial services',
        '82'	=> 'Playground',
        '83'	=> 'Golf',
        '84'	=> 'Flyboard',
        '85'	=> 'Amphibious car',
        '86'	=> 'Beach games',
        '87'	=> 'Bikes',
        '88'	=> 'Canoe',
        '89'	=> 'Diving',
        '90'	=> 'Fishing',
        '91'	=> 'Floating pool',
        '92'	=> 'Hoverboard',
        '93'	=> 'Hovercraft',
        '94'	=> 'Inflatables',
        '95'	=> 'Water slide',
        '96'	=> 'Waterpark',
        '97'	=> 'Jet ski',
        '98'	=> 'Kite surf',
        '99'	=> 'Paddle',
        '100'	=> 'Scooter',
        '101'	=> 'Seabob',
        '102'	=> 'Segway',
        '103'	=> 'Wakeboard',
        '104'	=> 'Simple flow ventilation',
        '105' => 'Double flow ventilation',
        '106'	=> 'Business center',
        '107'	=> 'Foodservice',
        '108'	=> 'Condominium garden',
        '109'	=> 'Stabilizers',
        '110'	=> 'Hydraulic Platform',
        '111'	=> 'Freezer',
        '112'	=> 'Concierge',
        '113'	=> 'Pantry',
        '114'	=> 'Fitness',
        '115'	=> 'Electric car terminal',
        '116'	=> 'Solar panels',
       );
      // $services = $services_arr[$servicesId];
      $services = array_intersect_key($services_arr,$servicesId);

 $category_arr = array(
'1' => 'Sale',
'2' => 'Rental',
 );
 $category =  $category_arr[$categoryId];

    // Creates a listing post
    $postInformation = array(
      'post_title' => wp_strip_all_tags(trim($postTitle)),
    'post_content' => $postContent,
      'post_type' => 'listing',
      'post_status' => 'publish',
      //'post_date' => $postUpdatedAt,
      'post_date' =>  $create_date,
    );

    // Verifies if the listing does not already exist
    //Проверяет, не существует ли список
    if ($postTitle != '') {
      $post = get_page_by_title($postTitle, OBJECT, 'listing');

      if (NULL === $post) {
        // Insert post and retrieve postId
        // Вставляем сообщение и получаем postId
        $postId = wp_insert_post($postInformation);
      }
      else {
        // Verifies if the property is not to old to be added
        // Проверяет, не устарело ли свойство для добавления
        if (strtotime($postUpdatedAt) >= strtotime('-5 days')) {
          return;
        }

        $postInformation['ID'] = $post->ID;
        $postId = $post->ID;

        // Update post
        wp_update_post($postInformation);
      }

      // Delete attachments that has been removed
      // Удаляем вложения, которые были удалены
      $attachments = get_attached_media('image', $postId);
      foreach ($attachments as $attachment) {
        $imageStillPresent = false;
        foreach ($images as $image) {
          if ($attachment->post_content == $image['id'] &&
            $this->getFileNameFromURL($attachment->guid) == $this->getFileNameFromURL($image['url'])) {
            $imageStillPresent = true;
          }
        }
        if (!$imageStillPresent) {
          wp_delete_attachment($attachment->ID, TRUE);
        }
      }

      // Updates the image and the featured image with the first given image
      // Обновляет изображение и избранное изображение первым заданным изображением
      $imagesIds = array();

      foreach ($images as $image) {
        // Tries to retrieve an existing media
        $media = $this->isMediaPosted($image['id']);

        // If the media does not exist, upload it
        if (!$media) {
          media_sideload_image($image['url'], $postId);

          // Retrieve the last inserted media
          $args = array(
            'post_type' => 'attachment',
            'numberposts' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
          );
          $medias = get_posts($args);

          // Just one media, but still an array returned by get_posts
          foreach ($medias as $attachment) {
            // Make sure the media's name is equal to the file name
            wp_update_post(array(
              'ID' => $attachment->ID,
              'post_name' => $postTitle,
              'post_title' => $postTitle,
            'post_content' => $image['id'],
            ));
            $media = $attachment;
          }
        }

        if (!empty($media) && !is_wp_error($media)) {
          $imagesIds[$image['rank']] = $media->ID;
        }

        // Set the first image as the thumbnail
        if ($image['rank'] == 1) {
          set_post_thumbnail($postId, $media->ID);
        }
      }

      $positions = implode(',', $imagesIds);
      update_post_meta($postId, 'images', $positions);

      // Updates custom meta
      update_post_meta($postId, 'type', esc_attr(strip_tags($type)));
      update_post_meta($postId, 'category', esc_attr(strip_tags($category))); 

      update_post_meta($postId, 'price', esc_attr(strip_tags($ctPrice)));
      update_post_meta($postId, 'ref', esc_attr(strip_tags($reference)));
      update_post_meta($postId, 'size', esc_attr(strip_tags($SqFt)));
      update_post_meta($postId, 'beds', esc_attr(strip_tags($beds)));
      update_post_meta($postId, 'rooms', esc_attr(strip_tags($rooms)));
      update_post_meta($postId, 'lat', esc_attr(strip_tags($Lat)));
      update_post_meta($postId, 'lng', esc_attr(strip_tags($Lng)));
  //    update_post_meta($postId, 'adress', esc_attr(strip_tags($address)));
      update_post_meta($postId, 'update', esc_attr(strip_tags($postUpdatedAt)));
     // update_post_meta($postId, 'benefits', esc_attr(strip_tags($services)));
     update_post_meta($postId, 'benefits', implode(",",$services) );
      update_post_meta($postId, 'district', esc_attr(strip_tags($City)));
      update_post_meta($postId, 'energetic1', esc_attr(strip_tags($energetic1)));
      update_post_meta($postId, 'energetic2', esc_attr(strip_tags($energetic2)));
      update_post_meta($postId, 'zip', esc_attr(strip_tags($Zip)));

      $value = array(
       "lat" => $Lat, "lng" => $Lng);
      update_post_meta($postId, 'map',  $value);

    // Updates custom meta agent
    /*
    update_post_meta($postId, 'agent_name', esc_attr(strip_tags($agent_name)));
    update_post_meta($postId, 'agent_phone', esc_attr(strip_tags($agent_phone)));
    update_post_meta($postId, 'agent_email', esc_attr(strip_tags($agent_email)));
    update_post_meta($postId, 'agent_photo', esc_attr(strip_tags($agent_photo)));
*/

    // update_post_meta($postId, '_ct_price_prefix', esc_attr(strip_tags($PricePrefix)));
    // update_post_meta($postId, '_ct_price_postfix', esc_attr(strip_tags($PricePostfix)));
    // update_post_meta($postId, '_ct_video', esc_attr(strip_tags($VideoURL)));
    // update_post_meta($postId, '_ct_mls', esc_attr(strip_tags($MLS)));
    // update_post_meta($postId, '_ct_latlng', esc_attr(strip_tags($LatLng)));
    // update_post_meta($postId, '_ct_listing_expire', esc_attr(strip_tags($ExpireListing)));
    // update_post_meta($postId, 'state', esc_attr(strip_tags( $State)));
    // update_post_meta($postId, 'zip', esc_attr(strip_tags($Zip)));
    //update_post_meta($postId, 'country', esc_attr(strip_tags($Country)));



      // Updates custom taxonomies
      wp_set_object_terms($postId,array($category,$City) ,  'listing_cat' );
     wp_set_object_terms($postId, $type, 'listing_type');
      /*wp_set_object_terms($postId, $category, 'post_tag');*/
    }
  }

  /**
   * Delete old listings
   * Удалить старые объявления
   * 
   * @param $properties
   */
  private function deleteOldListingPost($properties)
  {
    $parsedProperties = array();

    // Parse once for all the properties
    // Разбираем один раз для всех свойств
    foreach ($properties as $property) {
      $parsedProperties[] = $this->parseJSONOutput($property);
    }

    // Retrieve the current posts
    // Получить текущие посты
    $posts = get_posts(array(
      'post_type' => 'listing',
      'numberposts' => -1,
    ));

    foreach ($posts as $post) {
      $postMustBeRemoved = true;

      // Verifies if the post exists
      // Проверяет, существуют ли посты
      foreach ($parsedProperties as $property) {
        $postTitle = $property['postTitle'][$this->siteLanguage];
        if ($postTitle == '') {
          foreach ($property['postTitle'] as $lang => $title) {
            $postTitle = $title;
          }
        }

        if ($postTitle == $post->post_title) {
          $postMustBeRemoved = false;
          break;
        }
      }

      // If not, we can execute the action
      if ($postMustBeRemoved) {
        // Delete the post
        wp_delete_post($post->ID);
      }
    }
  }

  /**
   * Verifies if a media is already posted or not for a given image URL.
   *
   * @access private
   * @param int $imageId
   * @return object
   */
  private function isMediaPosted($imageId)
  {
    $args = array(
      'post_type' => 'attachment',
      'posts_per_page' => -1,
      'post_status' => 'any',
      'content' => $imageId,
    );

    $medias = ApimoWPRESynchronizer_PostsByContent::get($args);

    if (isset($medias) && is_array($medias)) {
      foreach ($medias as $media) {
        return $media;
      }
    }

    return null;
  }
 
  /**
   * Return the filename for a given URL.
   *
   * @access private
   * @param string $imageUrl
   * @return string $filename
   */
  private function getFileNameFromURL($imageUrl)
  {
    $imageUrlData = pathinfo($imageUrl);
    return $imageUrlData['filename'];
  }

  /**
   * Calls the Apimo API
   *
   * @access private
   * @param string $url The API URL to call
   * @param string $method The HTTP method to use
   * @param array $body The JSON formatted body to send to the API
   * @return array $response
   */
  private function callApimoAPI($url, $method, $body = null)
  {
    $headers = array(
      'Authorization' => 'Basic ' . base64_encode(
          get_option('apimo_WPRE_synchronizer_settings_options')['apimo_api_provider'] . ':' .
          get_option('apimo_WPRE_synchronizer_settings_options')['apimo_api_token']
        ),
      'content-type' => 'application/json',
    );

    if (null === $body || !is_array($body)) {
      $body = array();
    }

    if (!isset($body['limit'])) {
      $body['limit'] = 100;
    }
    if (!isset($body['offset'])) {
      $body['offset'] = 0;
    }

    $request = new WP_Http;
    $response = $request->request($url, array(
      'method' => $method,
      'headers' => $headers,
      'body' => $body,
    ));

    if (is_array($response) && !is_wp_error($response)) {
      $headers = $response['headers']; // array of http header lines
      $body = $response['body']; // use the content
    } else {
      $body = $response->get_error_message();
    }

    return array(
      'headers' => $headers,
      'body' => $body,
    );
  }

  /**
   * Activation hook
   */
  public function install()
  {
    if (!wp_next_scheduled('apimo_WPRE_synchronizer_hourly_event')) {
      wp_schedule_event(time(), 'daily', 'apimo_WPRE_synchronizer_hourly_event');
    }
  }

  /**
   * Deactivation hook
   */
  public function uninstall()
  {
    wp_clear_scheduled_hook('apimo_WPRE_synchronizer_hourly_event');
  }
}
