<?php
	namespace BigTree;
	use DateTime;
	
	if ($this->Input) {
		$date = DateTime::createFromFormat(Router::$Config["date_format"]." h:i a",
										   Auth::user()->convertTimestampFrom($this->Input));
		
		// Fallback to SQL standards for existing values
		if (!$date) {
			$date = DateTime::createFromFormat("Y-m-d H:i:s", Auth::user()->convertTimestampFrom($this->Input));
		}
	
		if ($date) {
			$this->Output = $date->format("Y-m-d H:i:s");
		} else {
			$this->Output = "";
		}
	}
	