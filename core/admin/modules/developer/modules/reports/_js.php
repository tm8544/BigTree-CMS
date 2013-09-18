<script>
	BigTree.localReportType = "<?=$type?>";
	BigTree.localCurrentField = false;
	BigTree.localHooks = function() {
		$("#field_table, #filter_table").sortable({ axis: "y", containment: "parent", handle: ".icon_sort", items: "li", placeholder: "ui-sortable-placeholder", tolerance: "pointer" });
		BigTreeCustomControls();
	};

	$("#report_table").change(function(event,data) {
		$("#field_area").load("<?=ADMIN_ROOT?>ajax/developer/load-report/", { table: data.value, report_type: $("#report_type").val() }, BigTree.localHooks);
		$("#create").show();
	});

	$("#report_type").change(function(event,data) {
		v = $(this).val();
		if (v == "csv") {
			$("#data_parser_function").show();
			$("#filtered_view").hide();
		} else {
			$("#data_parser_function").hide();
			$("#filtered_view").show();
		}
	});
	
	$("#field_area").on("click",".icon_delete",function() {
		li = $(this).parents("li");
		title = li.find("input").val();
		if (title) {
			key = $(this).attr("name");
			if (key != "geocoding") {
				fieldSelect.addField(key,title);
			}
		}
		li.remove();
		return false;
	});
	
	BigTree.localHooks();
	new BigTreeFormValidator("form.module");
</script>	