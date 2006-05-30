-- Privileges for the DOMjudge MySQL tables.
-- This assumes database name 'domjudge'
--
-- You can pipe this file into the 'mysql' command to set these permissions.
--
-- THIS FILE SHOULD ALWAYS BE NON-READABLE!
-- (because of database-login usernames/passwords)
--
-- $Id$

USE mysql;

-- Add users and passwords
-- Change these default passwords, and change them in etc/passwords.php
-- too! This can be done automatically with 'make gen_passwd' from the
-- SYSTEM_ROOT dir.
INSERT INTO user (Host, User, Password) VALUES ('localhost','domjudge_jury'  ,PASSWORD('DOMJUDGE_JURY_PASSWD'));
INSERT INTO user (Host, User, Password) VALUES ('localhost','domjudge_team'  ,PASSWORD('DOMJUDGE_TEAM_PASSWD'));
INSERT INTO user (Host, User, Password) VALUES ('localhost','domjudge_public',PASSWORD('DOMJUDGE_PUBLIC_PASSWD'));

-- Juryaccount can do anything to the database
INSERT INTO db (Host, Db, User, Select_priv, Insert_priv, Update_priv, Delete_priv) VALUES ('localhost','domjudge','domjudge_jury','Y','Y','Y','Y');

-- Other privileges
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','submission','cid'         ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','submission','probid'      ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','submission','submitid'    ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','submission','submittime'  ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','submission','team'        ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','problem'   ,'allow_submit',NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','problem'   ,'name'        ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','problem'   ,'cid'         ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','problem'   ,'probid'      ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','judging'   ,'valid'       ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','judging'   ,'result'      ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','judging'   ,'submitid'    ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_public','judging'   ,'judgingid'   ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'problem'   ,'allow_submit',NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'problem'   ,'cid'         ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'problem'   ,'name'        ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'problem'   ,'probid'      ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'language'  ,'langid'      ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'language'  ,'name'        ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'language'  ,'extension'   ,NOW(),'Select');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'team'      ,'ipaddress'   ,NOW(),'Update');
INSERT INTO columns_priv VALUES ('localhost','domjudge','domjudge_team'  ,'team'      ,'teampage_first_visited',NOW(),'Update');

INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_public','scoreboard_public','domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_public','team'             ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_public','submission'       ,'domjudge@localhost',NOW(),'','Select');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_public','problem'          ,'domjudge@localhost',NOW(),'','Select');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_public','judging'          ,'domjudge@localhost',NOW(),'','Select');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_public','contest'          ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_public','team_category'         ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_public','team_affiliation'         ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'scoreboard_public','domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'scoreboard_jury'  ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'clarification'    ,'domjudge@localhost',NOW(),'Select,Insert','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'team_category'         ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'team_affiliation'         ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'language'         ,'domjudge@localhost',NOW(),'','Select');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'problem'          ,'domjudge@localhost',NOW(),'','Select');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'team'             ,'domjudge@localhost',NOW(),'Select','Update');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'contest'          ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'submission'       ,'domjudge@localhost',NOW(),'Select','');
INSERT INTO tables_priv  VALUES ('localhost','domjudge','domjudge_team'  ,'judging'          ,'domjudge@localhost',NOW(),'Select','');

FLUSH PRIVILEGES;
