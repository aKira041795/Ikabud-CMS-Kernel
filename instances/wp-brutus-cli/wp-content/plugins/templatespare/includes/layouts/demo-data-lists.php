<?php
function  templatespare_templates_demo_list($targetSlug = '')
{


  $all_demos = array();

  ob_start();

  //$upload_path = wp_get_upload_dir();
  $remote_json_url = "https://raw.githubusercontent.com/afthemes/templatespare-demo-data/master/demo-list.json";
  //$remote_json_url =$upload_path['baseurl']."/templatespare-demo-data/demo-list.json";

  $response = wp_remote_get($remote_json_url);


  if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
    // Get the body of the response
    $remote_json_content = wp_remote_retrieve_body($response);

    // Decode the JSON content
    $all_demos = json_decode($remote_json_content, true);
  } else {
    // Handle error, if any
    $error_message = is_wp_error($response) ? $response->get_error_message() : 'HTTP request failed';
    error_log("Error: $error_message");
  }

  ob_get_clean();
  $matchedData = [];
  if ($targetSlug == 'all') {

    foreach ($all_demos['democontent'] as $key => $res) {
      $matchedData[$key]['data'] = $res['data'];
      $matchedData[$key]['free'] = $res['free'];
      $matchedData[$key]['premium'] = $res['premium'];
      $matchedData[$key]['demodata'] = $res['demodata'];
    }


    return $matchedData;
  } else {
    foreach ($all_demos as $res) {
      $data = $res[$targetSlug]['demodata'];
      $free = $res[$targetSlug]['free'];
      $premium = $res[$targetSlug]['premium'];

      $child =  isset($res[$targetSlug]['child']) ? $res[$targetSlug]['child'] : "";


      $matchedData[$targetSlug]['free'] = ($free == '') ? $targetSlug : $free;
      $matchedData[$targetSlug]['premium'] = ($premium == '') ? $targetSlug : $premium;
      $matchedData[$targetSlug]['demodata'] = $res[$targetSlug]['demodata'];
      $matchedData[$targetSlug]['data'] = $res[$targetSlug]['data'];
      if (!empty($free)) {
        $child =  isset($res[$free]['child']) ? $res[$free]['child'] : "";
        $matchedData[$free]['free'] = $free;
        $matchedData[$free]['premium'] = $premium;
        $matchedData[$free]['demodata'] = isset($res[$free]['demodata']) ? $res[$free]['demodata'] : [];
        $matchedData[$free]['data'] = isset($res[$free]['data']) ? $res[$free]['data'] : [];
      }
      if (!empty($child)) {
        foreach ($child as $child_theme) {
          $child_themes = $res[$child_theme];
          $matchedData[$child_theme]['data'] = $child_themes['data'];
          $matchedData[$child_theme]['free'] = $child_themes['free'];
          $matchedData[$child_theme]['premium'] = $child_themes['premium'];
          $matchedData[$child_theme]['demodata'] = $child_themes['demodata'];
        }
      }
      if (!empty($premium)) {
        $matchedData[$premium]['data'] = $res[$premium]['data'];
        $matchedData[$premium]['free'] = ($free == '') ? $targetSlug : $free;
        $matchedData[$premium]['premium'] = $premium;
        $matchedData[$premium]['demodata'] = $res[$premium]['demodata'];
      }
    }



    return $matchedData;
  }
}

function templatespare_get_filtered_data($theme = '')
{

  $all_demos = templatespare_templates_demo_list('all');
  $final_demodata = array();
  $empty_array = array();
  foreach ($all_demos as $keys => $demos) {
    if (isset($demos['demodata'])) {
      foreach ($demos['demodata'] as $demo) {
        $final_demodata[] = $demo;
      }
    }
    $empty_array['demos'][] = $keys;
  }



  $final_demotags = array();
  $demodata = array();
  foreach ($final_demodata as $demos) {
    if (isset($demos['main_category'])) {
      //foreach ($demos['tags'] as $demo_tags) {
      //$final_demotags[] = $demo_tags;
      $final_demotags[] = $demos['main_category'];
      //}
    }
  }
  $unique_demotags = array_count_values($final_demotags);
  ksort($unique_demotags);

  $demodata['demos'] =  $unique_demotags;
  $demodata['counts'] =  count($final_demodata);
  $demodata['url'] =  count($final_demodata);
  return  $demodata;
}

function templatespare_get_tags_filtered_data($theme = '')
{
  $all_demos = templatespare_templates_demo_list($theme);
  $final_demodata = array();
  $empty_array = array();

  foreach ($all_demos as $keys => $demos) {
    if (isset($demos['demodata'])) {
      foreach ($demos['demodata'] as $demo) {
        $final_demodata[] = $demo;
      }
    }

    $empty_array['demos'][] = $keys;
  }


  $final_demotags = array();
  $demodata = array();
  foreach ($final_demodata as $demos) {
    if (isset($demos['tags'])) {
      foreach ($demos['tags'] as $demo_tags) {
        $final_demotags[] = $demo_tags;
      }
    }
  }
  $unique_demotags = array_count_values($final_demotags);
  ksort($unique_demotags);

  $demodata['demos'] =  $unique_demotags;
  $demodata['counts'] =  count($final_demodata);
  $demodata['url'] =  count($final_demodata);
  return  $demodata;
}

function templatespare_get_main_category_filtered_data($theme = '')
{
  $all_demos = templatespare_templates_demo_list($theme);
  $final_demodata = array();
  $empty_array = array();

  foreach ($all_demos as $keys => $demos) {
    if (isset($demos['demodata'])) {
      foreach ($demos['demodata'] as $demo) {
        $final_demodata[] = $demo;
      }
    }

    $empty_array['demos'][] = $keys;
  }


  $final_demotags = array();
  $demodata = array();
  foreach ($final_demodata as $demos) {
    if (isset($demos['tags'])) {
      //foreach ($demos['tags'] as $demo_tags) {
      //$final_demotags[] = $demo_tags;
      $final_demotags[] = $demos['main_category'];
      //}
    }
  }
  $unique_demotags = array_count_values($final_demotags);
  ksort($unique_demotags);

  $demodata['demos'] =  $unique_demotags;
  $demodata['counts'] =  count($final_demodata);
  $demodata['url'] =  count($final_demodata);
  return  $demodata;
}
function templatespare_get_filtered_pro_themes()
{
  $all_demos = templatespare_templates_demo_list('all');

  $final_demodata = array();

  foreach ($all_demos as $keys => $demos) {
    if (isset($demos['demodata'])) {
      foreach ($demos['demodata'] as $demo) {
        if (strpos($demo['theme'], 'Pro') || strpos($demo['theme'], 'Plus')) {
          $final_demodata[$keys] = $demo['theme'];
        }
      }
    }
  }



  return $final_demodata;
}




function templatespare_cheeck_pro_themes()
{
  $available_theme = templatespare_get_filtered_pro_themes();
  $theme = wp_get_theme();
  $pro_theme_lists = [];

  foreach ($available_theme as $res) {

    foreach ((array) wp_get_themes() as $theme_dir => $themes) {


      if (in_array($res, templatespare_available_pro_themes()) && $res == $themes->name) {

        $pro_theme_lists[] = $res; //
        //return  $res;


      }
    }
  }

  return $pro_theme_lists;
}
