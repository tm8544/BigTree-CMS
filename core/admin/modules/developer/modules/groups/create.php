<?php
	namespace BigTree;
	
	ModuleGroup::create($_POST["name"]);
	Utils::growl("Developer","Created Module Group");
	Router::redirect(DEVELOPER_ROOT."modules/groups/");
	