-- The WordPress test library drops every table in its database, so integration
-- tests get one of their own rather than sharing the site's.
CREATE DATABASE IF NOT EXISTS `wordpress_test`;
GRANT ALL ON `wordpress_test`.* TO 'root'@'%';
