<?php
	namespace BigTree;

	/**
	 * @global string $type
	 */
	
	$type = isset($_POST["type"]) ? $_POST["type"] : $type;
	$page = isset($_POST["page"]) ? intval($_POST["page"]) : 1;
	$search = isset($_POST["search"]) ? $_POST["search"] : "";
	$active_site = null;
	$tabindex = 0;
	
	if (isset($_POST["site_key"])) {
		Cookie::create("bigtree_admin[active_site]", $_POST["site_key"]);
	}
	
	// Multi-site can only load one site's keys at once
	if (is_array(Router::$Config["sites"]) && count(Router::$Config["sites"]) > 1) {
		$active_site = $_POST["site_key"] ?: Cookie::get("bigtree_admin[active_site]");
		
		if (!$active_site) {
			$keys = array_keys(Router::$Config["sites"]);
			$active_site = $keys[0];
		}
		
		list($pages, $items) = Redirect::search($type, $search, $page, $active_site, true);
	} else {
		list($pages, $items) = Redirect::search($type, $search, $page, null, true);
	}

	foreach ($items as $item) {
		$tabindex++;
		
		if ($active_site) {
			$domain_to_replace = Router::$Config["sites"][$active_site]["www_root"];
		} else {
			$domain_to_replace = WWW_ROOT;
		}
		
		$target = str_replace($domain_to_replace, "", $item["redirect_url"]);
?>
<li>
	<section class="requests_404"><?=$item["requests"]?></section>
	<section class="url_404"><?=$item["broken_url"]?><?php if ($item["get_vars"]) { echo "?".$item["get_vars"]; } ?></section>
	<section class="redirect_404">
		<input type="text" tabindex="<?=$tabindex?>" name="<?=$item["id"]?>" id="404_<?=$item["id"]?>" class="autosave" value="<?=$target?>"<?php if ($item["redirect_url"] && !$target) { ?> placeholder="Homepage"<?php } ?> />
	</section>
	<?php if ($type == "ignored") { ?>
	<section class="ignore_404"><a href="#<?=$item["id"]?>" class="icon_restore"></a></section>	
	<?php } else { ?>
	<section class="ignore_404"><a href="#<?=$item["id"]?>" class="icon_archive"></a></section>	
	<?php } ?>
	<section class="ignore_404"><a href="#<?=$item["id"]?>" class="icon_delete"></a></section>
</li>
<?php
	}
?>
<script>
	BigTree.setPageCount("#view_paging",<?=$pages?>,<?=$page?>);
</script>