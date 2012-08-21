<?php
/*
 * @version $Id: commondbconnexity.class.php 19060 2012-07-27 07:53:55Z webmyster $
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2012 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

/// Common DataBase Connexity Table Manager Class
/// This class factorize code for CommonDBChild and CommonDBRelation
/// @since 0.84
abstract class CommonDBConnexity extends CommonDBTM {

   const DONT_CHECK_ITEM_RIGHTS  = 1; // Don't check the parent => always can*Child
   const HAVE_VIEW_RIGHT_ON_ITEM = 2; // canXXXChild = true if parent::canView == true
   const HAVE_SAME_RIGHT_ON_ITEM = 3; // canXXXChild = true if parent::canXXX == true

   static public $canDeleteOnItemClean = true;

   /**
    * Return the SQL request to get all the connexities corresponding to $itemtype[$items_id]
    * That is used by cleanDBOnItem : the only interesting field is static::getIndexName()
    * But CommonDBRelation also use it to get more complex result
    *
    * @param $itemtype the type of the item to look for
    * @param $items_id the id of the item to look for
    *
    * @result the SQL request of '' if it is not possible
    **/
   protected static function getSQLRequestToSearchForItem($itemtype, $items_id) {
      return '';
   }


   /**
    * Clean the Connecity Table when item of the relation is deleted
    * To be call from the cleanDBonPurge of each Item class
    *
    * @param $itemtype  type of the item
    * @param $item_id   id of the item
   **/
   function cleanDBonItemDelete ($itemtype, $items_id) {
      global $DB;

      $query = static::getSQLRequestToSearchForItem($itemtype, $items_id);

      if (!empty($query)) {
         $input = array('_no_history' => true,
                        '_no_notif'   => true);

         foreach ($DB->request($query) as $data) {
            $input[$this->getIndexName()] = $data[$this->getIndexName()];
            $this->delete($input);
         }
      }
   }


   /**
    * get associated item (defined by $itemtype and $items_id)
    *
    * @see inc/CommonDBConnexity::getItemFromArray()
    *
    * @param $itemtype  the name of the field of the type of the item to get
    * @param $items_id  the name of the field of the id of the item to get
    * @param $getFromDB (bool) do we have to load the item from the DB ?
    * @param $getEmpty  (bool) else : do we have to load an empty item ?
    *
    * @result the item or false if we cannot load the item
   **/
   function getConnexityItem($itemtype, $items_id, $getFromDB = true, $getEmpty = true) {
      return static::getItemFromArray($itemtype, $items_id, $this->fields, $getFromDB, $getEmpty);
   }


   /**
    * get associated item (defined by $itemtype and $items_id)
    *
    * @param $itemtype  the name of the field of the type of the item to get
    * @param $items_id  the name of the field of the id of the item to get
    * @param $array     the array in we have to search ($input, $this->fields ...)
    * @param $getFromDB (bool) do we have to load the item from the DB ?
    * @param $getEmpty  (bool) else : do we have to load an empty item ?
    *
    * @result the item or false if we cannot load the item
   **/
   static function getItemFromArray($itemtype, $items_id, array $array,
                                    $getFromDB = true, $getEmpty = true) {

      if (preg_match('/^itemtype/', $itemtype)) {
         if (isset($array[$itemtype])) {
            $type = $array[$itemtype];
         } else {
            $type = '';
         }
      } else {
         $type = $itemtype;
      }

      $item = getItemForItemtype($type);
      if ($item !== false) {
         if ($getFromDB) {
            if ((isset($array[$items_id]))
                && ($item->getFromDB($array[$items_id]))) {
               return $item;
            }
         } else if ($getEmpty) {
            if ($item->getEmpty()) {
               return $item;
            }
         } else {
            return $item;
         }
         unset($item);
      }

      return false;
   }


   /**
    * Factorization of canCreate, canView, canUpate and canDelete. It checks the ability to
    * create, view, update or delete the item if it is possible (ie : $itemtype != 'itemtype')
    *
    * @param $method     the method to check (canCreate, canView, canUpdate of canDelete)
    * @param $item_right the right to check (DONT_CHECK_ITEM_RIGHTS, HAVE_VIEW_RIGHT_ON_ITEM ...)
    * @param $itemtype   the name of the field of the type of the item to get
    * @param $items_id   the name of the field of the id of the item to get
    *
    * @result true if we have absolute right to create the current connexity
   **/
   static function canConnexity($method, $item_right, $itemtype, $items_id) {
      if (($item_right != self::DONT_CHECK_ITEM_RIGHTS)
          &&(!preg_match('/^itemtype/', $itemtype))) {
         switch ($item_right) {
            case self::HAVE_VIEW_RIGHT_ON_ITEM:
               if (!$itemtype::canView()) {
                  return false;
               }
            case self::HAVE_SAME_RIGHT_ON_ITEM:
               if (!$itemtype::$method()) {
                  return false;
               }
         }
      }
      return true;
   }


   /**
    * Factorization of canCreateItem, canViewItem, canUpateItem and canDeleteItem. It checks the
    * ability to create, view, update or delete the item. If we cannot check the item (none is
    * existing), then we can do the action of the current connexity
    *
    * @param $methodItem    the method to check (canCreateItem, canViewItem, canUpdateItem of
                            canDeleteItem)
    * @param $methodNotItem the method to check (canCreate, canView, canUpdate of canDelete)
    * @param $item_right    the right to check (DONT_CHECK_ITEM_RIGHTS, HAVE_VIEW_RIGHT_ON_ITEM ...)
    * @param $itemtype      the name of the field of the type of the item to get
    * @param $items_id      the name of the field of the id of the item to get
    * @param &$item         the item concerned by the item
    *
    * @result true if we have absolute right to create the current connexity
   **/
   function canConnexityItem($methodItem, $methodNotItem, $item_right, $itemtype, $items_id,
                             &$item = NULL) {
      $item = $this->getConnexityItem($itemtype, $items_id);
      if ($item_right != self::DONT_CHECK_ITEM_RIGHTS) {
         if ($item !== false) {
            // here, we can check item's global rights
            if ((preg_match('/^itemtype/', $itemtype))
                && (!$item->$methodNotItem())) {
               return false;
            }
            switch ($item_right) {
               case self::HAVE_VIEW_RIGHT_ON_ITEM:
                  return $item->canViewItem();
               case self::HAVE_SAME_RIGHT_ON_ITEM:
                  return $item->$methodItem();
            }
         } else {
            // if we cannot get the parent, then we cannot check its rights
            return false;
         }
      }
      return parent::$methodItem();
   }


   /**
    * Factorized method to search difference when updating a connexity : return both previous
    * item and new item if both are different. Otherwise returns new items
    *
    * @param $itemtype      the name of the field of the type of the item to get
    * @param $items_id      the name of the field of the id of the item to get
    *
    * @result array containing "previous" (if exists) and "new". Beware that both can be equal
    *         to false
   **/
   function getItemsForLog($itemtype, $items_id) {

      $newItemArray[$items_id] = $this->fields[$items_id];
      if (isset($this->oldvalues[$items_id])) {
         $previousItemArray[$items_id] = $this->oldvalues[$items_id];
      } else {
         $previousItemArray[$items_id] = $this->fields[$items_id];
      }

      if (preg_match('/^itemtype/', $itemtype)) {
         $newItemArray[$itemtype] = $this->fields[$itemtype];
         if (isset($this->oldvalues[$itemtype])) {
            $previousItemArray[$itemtype] = $this->oldvalues[$itemtype];
         } else {
            $previousItemArray[$itemtype] = $this->fields[$itemtype];
         }
      }

      $result = array('new' => self::getItemFromArray($itemtype, $items_id, $newItemArray));
      if ($previousItemArray !== $newItemArray) {
         $result['previous'] = self::getItemFromArray($itemtype, $items_id, $previousItemArray);
      }

      return $result;
   }

}
?>
