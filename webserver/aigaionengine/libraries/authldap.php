<?php if (!defined('BASEPATH')) exit('No direct script access allowed'); ?><?php
/**
 * class.AuthLdap.php , version 0.2
 * Mark Round, April 2002 - http://www.markround.com/unix
 * Provides LDAP authentication and user functions.
 *
 * Not intended as a full-blown LDAP access class - but it does provide
 * several useful functions for dealing with users.
 * Note - this works out of the box on Sun's iPlanet Directory Server - but
 * an ACL has to be defined giving all users the ability to change their
 * password (userPassword attribute).
 * See the README file for more information and examples.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * ChangeLog
 * ---------
 * version 0.2, 11.04.2003, Michael Joseph <michael@jamwarehouse.com> 
 * - Added switches and workarounds for Active Directory integration
 * - Change documentation to phpdoc style (http://phpdocu.sourceforge.net)
 * - Added a constructor
 * - Added an attribute array parameter to the getUsers method
 */

class Authldap {

    // 1.1 Public properties -----------------------------------------------------
    /**
     * Array of server IP address or hostnames
     */
    var $server;
    /**
     * The base DN (e.g. "dc=foo,dc=com")
     */
    var $dn;
    /**
     * the directory server, currently supports iPlanet and Active Directory
     */
    var $serverType;
    /**
     * Active Directory authenticates using user@domain
     */
    var $domain;
    /**
     * The user to authenticate with when searching
     * Active Directory doesn't support anonymous access
     */
    var $searchUser;
    /**
     * The password to authenticate with when searching
     * Active Directory doesn't support anonymous access
     */
    var $searchPassword;
    /**
     *  Where the user records are kept
     */
    var $people;
    /**
     * Where the group definitions are kept
     */
    var $groups;
    /**
     * The last error code returned by the LDAP server
     */
    var $ldapErrorCode;
    /**
     * Text of the error message
     */
    var $ldapErrorText;

    // 1.2 Private properties ----------------------------------------------------
    /**
     * The internal LDAP connection handle
     */
    var $connection;
    /**
     * Result of any connections etc.
     */
    var $result;

    /**
     * Constructor- creates a new instance of the authentication class
     *
     * @param string the ldap server to connect to
     * @param string the base dn
     * @param string the server type- current supports iPlanet and ActiveDirectory
     * @param string the domain to use when authenticating against Active Directory
     * @param string the username to authenticate with when searching if anonymous binding is not supported
     * @param string the password to authenticate with when searching if anonymous binding is not supported
     */
    function AuthLdap ($sLdapServer="", $sBaseDN="", $sServerType="", $sDomain = "", $searchUser = "", $searchPassword = "") {
        $this->server = array($sLdapServer);
        $this->dn = $sBaseDN;
        $this->serverType = $sServerType;
        $this->domain = $sDomain;
        $this->searchUser = $searchUser;
        $this->searchPassword = $searchPassword;
    }
    
    // 2.1 Connection handling methods -------------------------------------------

    /**
     * 2.1.1 : Connects to the server. Just creates a connection which is used
     * in all later access to the LDAP server. If it can't connect and bind
     * anonymously, it creates an error code of -1. Returns true if connected,
     * false if failed. Takes an array of possible servers - if one doesn't work,
     * it tries the next and so on.
     */
    function connect() {
        foreach ($this->server as $key => $host) {
            $this->connection = ldap_connect( $host);
            if ( $this->connection) {
                if ($this->serverType == "ActiveDirectory") {
                    return true;
                } else {
                    // Connected, now try binding anonymously
                    $this->result=@ldap_bind( $this->connection);
                }
                return true;
            }
        }

        $this->ldapErrorCode = -1;
        $this->ldapErrorText = "Unable to connect to any server";
        return false;
    }

    /**
     * 2.1.2 : Simply closes the connection set up earlier.
     * Returns true if OK, false if there was an error.
     */
    function close() {
        if ( !@ldap_close( $this->connection)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        } else {
            return true;
        }
    }

    /**
     * 2.1.3 : Anonymously binds to the connection. After this is done,
     * queries and searches can be done - but read-only.
     */
    function bind() {
        if ( !$this->result=@ldap_bind( $this->connection)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        } else {
            return true;
        }
    }



    /**
     * 2.1.4 : Binds as an authenticated user, which usually allows for write
     * access. The FULL dn must be passed. For a directory manager, this is
     * "cn=Directory Manager" under iPlanet. For a user, it will be something
     * like "uid=jbloggs,ou=People,dc=foo,dc=com".
     */    
    function authBind( $bindDn,$pass) {
        ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, 3) ;
        if ( !$this->result = @ldap_bind( $this->connection,$bindDn,$pass)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        } else {
            return true;
        }
    }

    // 2.2 Password methods ------------------------------------------------------

    /**
     * 2.2.1 : Checks a username and password - does this by logging on to the
     * server as a user - specified in the DN. There are several reasons why
     * this login could fail - these are listed below.
     */
    function checkPass( $uname,$pass) {
        /* Construct the full DN, eg:-
        ** "uid=username, ou=People, dc=orgname,dc=com"
        */
        if ($this->serverType == "ActiveDirectory") {
            $checkDn = "$uname@$this->domain";
        } else {
            $checkDn = $this->getUserIdentifier() . "=$uname, " . $this->setDn(true);
        }
        // Try and connect...
        $this->result = @ldap_bind( $this->connection,$checkDn,$pass);
        if ( $this->result) {
            // Connected OK - login credentials are fine!
            return true;
        } else {
            /* Login failed. Return false, together with the error code and text from
            ** the LDAP server. The common error codes and reasons are listed below :
            ** (for iPlanet, other servers may differ)
            ** 19 - Account locked out (too many invalid login attempts)
            ** 32 - User does not exist
            ** 49 - Wrong password
            ** 53 - Account inactive (manually locked out by administrator)
            */
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        }
    }


    /**
     * 2.2.2 : Allows a password to be changed. Note that on most LDAP servers,
     * a new ACL must be defined giving users the ability to modify their
     * password attribute (userPassword). Otherwise this will fail.
     */
    function changePass( $uname,$oldPass,$newPass) {
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        if ($this->serverType == "ActiveDirectory") {
            $checkDn = "$uname@$this->domain";
        } else {
            $checkDn = $this->getUserIdentifier() . "=$uname, " . $this->setDn(true);
        }
        $this->result = @ldap_bind( $this->connection,$checkDn,$oldPass);

        if ( $this->result) {
            // Connected OK - Now modify the password...
            $info["userPassword"] = $newPass;
            $this->result = @ldap_modify( $this->connection, $checkDn, $info);
            if ( $this->result) {
                // Change went OK
                return true;
            } else {
                // Couldn't change password...
                $this->ldapErrorCode = ldap_errno( $this->connection);
                $this->ldapErrorText = ldap_error( $this->connection);
                return false;
            }
        } else {
            // Login failed - see checkPass method for common error codes
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        }
    }


    /**
     * 2.2.3 : Returns days until the password will expire.
     * We have to explicitly state this is what we want returned from the
     * LDAP server - by default, it will only send back the "basic"
     * attributes.
     */
    function checkPassAge ( $uname) {

        $results[0] = "passwordexpirationtime";
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $checkDn = $this->setDn(true);
        $this->result = @ldap_search( $this->connection,$checkDn,$this->getUserIdentifier()."=$uname",$results);

        if ( !$info=@ldap_get_entries( $this->connection, $this->result)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        } else {
            /* Now work out how many days remaining....
            ** Yes, it's very verbose code but I left it like this so it can easily 
            ** be modified for your needs.
            */
            $date  = $info[0]["passwordexpirationtime"][0];
            $year  = substr( $date,0,4);
            $month = substr( $date,4,2);
            $day   = substr( $date,6,2);
            $hour  = substr( $date,8,2);
            $min   = substr( $date,10,2);
            $sec   = substr( $date,12,2);

            $timestamp = mktime( $hour,$min,$sec,$month,$day,$year);
            $today  = mktime();
            $diff   = $timestamp-$today;
            return round( ( ( ( $diff/60)/60)/24));
        }
    }

    // 2.3 Group methods ---------------------------------------------------------

    /**
     * 2.3.1 : Checks to see if a user is in a given group. If so, it returns
     * true, and returns false if the user isn't in the group, or any other
     * error occurs (eg:- no such user, no group by that name etc.)
     */
    function checkGroup ( $uname,$group) {
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $checkDn = $this->setDn(false);

        // We need to search for the group in order to get it's entry.
        $this->result = @ldap_search( $this->connection, $checkDn, "cn=" .$group);
        $info = @ldap_get_entries( $this->connection, $this->result);

        // Only one entry should be returned(no groups will have the same name)
        $entry = ldap_first_entry( $this->connection,$this->result);

        if ( !$entry) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;  // Couldn't find the group...
        }
        // Get all the member DNs
        if ( !$values = @ldap_get_values( $this->connection, $entry, "uniqueMember")) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false; // No users in the group
        }

        foreach ( $values as $key => $value) {
            /* Loop through all members - see if the uname is there...
            ** Also check for sub-groups - this allows us to define a group as
            ** having membership of another group.
            ** FIXME:- This is pretty ugly code and unoptimised. It takes ages
            ** to search if you have sub-groups.
            */
            list( $cn,$ou) = explode( ",",$value);
            list( $ou_l,$ou_r) = explode( "=",$ou);

            if ( $this->groups==$ou_r) {
                list( $cn_l,$cn_r) = explode( "=",$cn);
                // OK, So we now check the sub-group...
                if ( $this->checkGroup ( $uname,$cn_r)) {
                    return true;
                }
            }

            if ( preg_match( "/$uname/i",$value)) {
                return true;
            }
        }
    }

    // 2.4 Attribute methods -----------------------------------------------------
    /**
     * 2.4.1 : Returns an array containing a set of attribute values.
     * For most searches, this will just be one row, but sometimes multiple
     * results are returned (eg:- multiple email addresses)
     */
    function getAttribute ( $uname,$attribute) {
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $checkDn = $this->setDn( true);
        $results[0] = $attribute;

        // We need to search for this user in order to get their entry.
        $this->result = @ldap_search( $this->connection,$checkDn,$this->getUserIdentifier()."=$uname",$results);
        $info = ldap_get_entries( $this->connection, $this->result);

        // Only one entry should ever be returned (no user will have the same uid)
        $entry = ldap_first_entry( $this->connection, $this->result);

        if ( !$entry) {
            $this->ldapErrorCode = -1;
            $this->ldapErrorText = "Couldn't find user";
            return false;  // Couldn't find the user...
        }

        // Get all the member DNs
        if ( !$values = @ldap_get_values( $this->connection, $entry, $attribute)) {
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false; // No matching attributes
        }

        // Return an array containing the attributes.
        return $values;
    }

    /**
     * 2.4.2 : Allows an attribute value to be set.
     * This can only usually be done after an authenticated bind as a
     * directory manager - otherwise, read/write access will not be granted.
     */
    function setAttribute( $uname, $attribute, $value) {
        // Construct a full DN...
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $attrib_dn = $this->getUserIdentifier()."=$uname," . $this->setDn(true);

        $info[$attribute] = $value;
        // Change attribute
        $this->result = ldap_modify( $this->connection, $attrib_dn, $info);
        if ( $this->result) {
            // Change went OK
            return true;
        } else {
            // Couldn't change password...
            $this->ldapErrorCode = ldap_errno( $this->connection);
            $this->ldapErrorText = ldap_error( $this->connection);
            return false;
        }
    }

    // 2.5 User methods ----------------------------------------------------------
    /**
     * 2.5.1 : Returns an array containing a details of users, sorted by
     * username. The search criteria is a standard LDAP query - * returns all
     * users.  The $attributeArray variable contains the required user detail field names
     */
    function getUsers( $search, $attributeArray) {
        // builds the appropriate dn, based on whether $this->people and/or $this->group is set
        $checkDn = $this->setDn( true);

        // Perform the search and get the entry handles
        
        // if the directory is AD, then bind first with the search user first
        if ($this->serverType == "ActiveDirectory") {
            $this->authBind($this->searchUser, $this->searchPassword);
        }
        $this->result = ldap_search( $this->connection, $checkDn, $this->getUserIdentifier() . "=$search");
        
        $info = ldap_get_entries( $this->connection, $this->result);
        for( $i = 0; $i < $info["count"]; $i++) {
            // Get the username, and create an array indexed by it...
            // Modify these as you see fit.
            $uname = $info[$i][$this->getUserIdentifier()][0];
            // add to the array for each attribute in my list
            for ( $j = 0; $j < count( $attributeArray); $j++) {
                if (strtolower($attributeArray[$j]) == "dn") {
                    $userslist["$uname"]["$attributeArray[$j]"]      = $info[$i][strtolower($attributeArray[$j])];
                } else {
                    $userslist["$uname"]["$attributeArray[$j]"]      = $info[$i][strtolower($attributeArray[$j])][0];
                }
            }
        }

        if ( !@asort( $userslist)) {
            /* Sort into alphabetical order. If this fails, it's because there
            ** were no results returned (array is empty) - so just return false.
            */
            $this->ldapErrorCode = -1;
            $this->ldapErrorText = "No users found matching search criteria ".$search;
            return false;
        }
        return $userslist;
    }

    // 2.6 helper methods
    
    /**
     * Sets and returns the appropriate dn, based on whether there
     * are values in $this->people and $this->groups.
     *
     * @param boolean specifies whether to build a groups dn or a people dn 
     * @return string if true ou=$this->people,$this->dn, else ou=$this->groups,$this->dn
     */
    function setDn($peopleOrGroups) {

        if ($peopleOrGroups) {
            if ( isset($this->people) && (strlen($this->people) > 0) ) {
                $checkDn = "ou=" .$this->people. ", " .$this->dn;
            }
        } else {
            if ( isset($this->groups) && (strlen($this->groups) > 0) ) {
                $checkDn = "ou=" .$this->groups. ", " .$this->dn;
            }
        }

        if ( !isset($checkDn) ) {
            $checkDn = $this->dn;
        }
        return $checkDn;
    }
    
    /**
     * Returns the correct user identifier to use, based on the ldap server type
     */
    function getUserIdentifier() {
        if ($this->serverType == "ActiveDirectory") {
            return "samaccountname";
        } else {
            return "uid";
        }
    }
} // End of class
?>