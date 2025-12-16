<?php

namespace BetterSlugs\Helper;

class Utils extends \Lime\Helper {

  /**
   * Slug generator
   *
   * @param  string  $format collection format
   * @param  string  $name   collection name
   * @param  object  $entry  collection entry
   * @param  boolean $lang   localized slugs generation
   *
   * @return string generated slug
   */
  public function generate($format, $name, $entry, $lang = FALSE) {

    // split by "/" or "_" but keep the separators
    $tokens = preg_split('~([/_])~', $format, -1, PREG_SPLIT_DELIM_CAPTURE);

    $out = [];

    foreach ($tokens as $token) {

      if ($token === '' || $token === null) {
        continue;
      }

      // keep separators as-is
      if ($token === '/' || $token === '_') {
        $out[] = $token;
        continue;
      }

      $part = $token;

      if ((bool) preg_match('/\[([a-zA-Z]+):([a-zA-Z-0-9_\|]+)\]/', $part)) {

        $part = str_replace(['[', ']'], '', $part);
        list($tokenKey, $tokenValue) = explode(":", $part);

        switch ($tokenKey) {

          case 'lang':
            $part = '';
            if ($lang) {
              $part = $lang;
            }
            break;

          case 'date':
            $part = date($tokenValue);
            break;

          case 'field':
            $part = '';
            if ($lang && isset($entry["{$tokenValue}_{$lang}"])) {
              $part = $entry["{$tokenValue}_{$lang}"];
            }
            elseif (isset($entry[$tokenValue])) {
              $part = $entry[$tokenValue];
            }
            break;

          case 'linkedField':
            $part = '';
            list($colName, $colField) = explode("|", $tokenValue);
            if (!isset($colName, $colField)) {
              break;
            }

            if (!empty($entry[$colName]['link'])) {
              $link = $entry[$colName]['link'];
              $id = $entry[$colName]['_id'];
              $linkedEntry = $this->app->module('collections')->findOne($link, ["_id" => $id]);

              if ($linkedEntry && isset($linkedEntry[$colField]) && !is_array($linkedEntry[$colField])) {
                if (isset($linkedEntry["{$colField}_{$lang}"])) {
                  $part = $linkedEntry["{$colField}_{$lang}"];
                }
                else {
                  $part = $linkedEntry[$colField];
                }
              }
            }
            break;

          case 'collection':
            $collection = $this->app->module('collections')->collection($name);
            $part = $collection[$tokenValue] ?? $collection['name'] ?? '';
            break;

          case 'callback':
            $part = '';
            if (function_exists($tokenValue)) {
              $part = call_user_func($tokenValue, $entry, $this->app, $lang);
            }
            break;
        }
      }

      $out[] = $this->app->helper('utils')->sluggify($part);
    }

    $slug = implode('', $out);

    // normalize and trim separators
    $slug = trim($slug, "/_");
    $slug = preg_replace('~/{2,}~', '/', $slug);
    $slug = preg_replace('~_{2,}~', '_', $slug);

    return $slug;
  }

  /**
   * Unique slug generator
   *
   * @param  string $name      collection name
   * @param  string $slug      slug to check and make unique
   * @param  string $fieldName name of the "slug" field
   * @param  string $_id       collection "_id" of current entry (if previously saved)
   *
   * @return string uniquely generated slug
   */
  public function getUnique($name, $slug, $fieldName, $_id = NULL) {

    $slug = trim($slug, "/_");
    $slug = preg_replace('~/{2,}~', '/', $slug);
    $slug = preg_replace('~_{2,}~', '_', $slug);

    // Check if slug is unique.
    $criteria[$fieldName] = $slug;

    // If is an update exclude current entry.
    if ($_id) {
      if ($this->app->storage->type === 'mongodb') {
        $criteria['_id'] = ['$ne' => new \MongoDB\BSON\ObjectID($_id)];
      }
      else {
        $criteria['_id'] = ['$ne' => $_id];
      }
    }

    $count = $this->app->module('collections')->count($name, $criteria);

    if ($count > 0) {
      $_slug = $slug;
      $slug = "{$slug}-{$count}";

      // Second check as we have now the numeric prefix value.
      if ($this->app->storage->type === 'mongodb') {
        $criteria[$fieldName] = new \MongoDB\BSON\Regex("^{$_slug}-[0-9]+$");
      }
      else {
        $criteria[$fieldName] = ['$regex' => "/^{$_slug}-[0-9]+$/"];
      }

      $count = $this->app->module('collections')->count($name, $criteria);

      if ($count > 0) {
        $count++;
        $slug = "{$_slug}-{$count}";
      }
    }

    return $slug;
  }

}
