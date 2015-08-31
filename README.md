#RocketPath

This package is used along side RocketSled to remove class names from URLs
and replace them with nice "SEO friendly" URLs.

#Rules Format

rules.php should define an array $rules.

Example:

	<?php
		$rules = array(
			'something/(.*)/other/(.*)' => array (
													'Userland\Homepage\Something',
													'param1',
													'param2',
													),
		);
	?>

The array key is a regular expression. You are free to write them as you want.

The array on the left contains:

* The runnable class full name. It will be mapped to $_GET['r']

* List of params. The number of params should be the same as the number of groups

captured by the regular expression.

According to this rule:

	'/something/123/other/hello-world'

will be mapped to

	'/?r=Userland\Homepage\Something&param1=123&param2=hello-world'

You need to pay attension to the order of the rules.

An example of a rule shadowing another rule is:

* 'something/(.*)'

* 'something/(.*)/other'

It is clear that a the url 'something/123/other' is intended for the second rule
but it will be captured by the first rule.

You can generate a set of default rules using the command:

	php index.php RocketPath generate-default-rules=1

A file called rules.default.php will be created. If you want to quickly test this package
you can generate default rules and rename the file rules.default.php to rules.php.

#Multiple Parameters For GET requests

For the rewrite to work as expected, you will need to edit the rules.php file to indicate
the parameters you need, for example;

If in the application, you need to access:

	?r=EyeonUnified\EyeonClient\EyeonSuburbPage\EyeonSuburbPage&suburb=Barton&postcode=221

for the rewrite to work appropriately, and display the SEO friendly url:

	http://the.base.url.com/eyeon-suburb-page/Barton/221

you need to edit the rules.php line from

	'eyeon-suburb-page' => array('EyeonUnified\EyeonClient\EyeonSuburbPage\EyeonSuburbPage',),

to

	'eyeon-suburb-page/(.+)/(.+)' => array('EyeonUnified\EyeonClient\EyeonSuburbPage\EyeonSuburbPage','suburb','postcode',),

#Nginx Configuration

You can generate the Nginx config file by running the following command:

	php index.php RocketPath rewrites=nginx filename=nginx_rocketpath_config folder=/etc/nginx/conf.d

You can replace 'nginx_rocketpath_config' and '/etc/nginx/conf.d' by the filename and the folder
of your choice.

The config will be saved to the file nginx_rocketpath_config, then this file will
be compared (usin SHA1 sum) to the one located under the folder /etc/nginx/conf.d.

If the two files are different, the command will tell you to update the old file
and restart Nginx.

You will need to include the file located at /etc/nginx/conf.d in the server main
configuration file. It should replace the directive "location / {...}".

#Initialisation

	<?php
		RocketSled::runnable(RocketPath::runnable($rules_file, $base_dir, $rewrites_enabled));
	?>

* $base_dir : Set base directory. This can be :
	- full URL : https://www.example.com/base-dir/ or
	- relative URL: /base-dir/

* $rewrites_enabled: You can set this to false to use the rules without updating
Nginx configuration.

#Parse URL

	<?php
	// RocketPath::parse_url function will check if the URL starts with '?r=' and
	// map the class name to its path using $paths array.
	// Be careful not to use it with :
	// - '/base-dir/?r=...'
	// - '/?r=...'
	// It will only recognise '?r=...' URLs

	// For example, to redirect the user, instead of this code:
	header('Location: ' . $url);

	// You should use the following code:
	header('Location: ' . RocketPath::parse_url($url));
	?>

#Parse Template

	<?php
	// In your template classes, after preparing the template and just before
	// calling "die($template->html());", you should parse the template
	// using RocketPath::parse_template() function:
	RocketPath::parse_template($template);

	// This function will loop through "a" and "form" tags and call parse_template
	// function to parse the content of "href" and "action" tags
	?>


