<?php

	$CENTERED = '<div style="text-align:center">%s</div>';

	$dict = array (
	//array ( inputcontainer, mathmod (default=true), outputcontainer (optional) ),
	//array ( '\$$(.*?)\$$', '<div style="text-align:center">%s</div>' )
		array ( '\\\\\((.*?)\\\\\)' ),
		array ( '\\\\\[(.*?)\\\\\)', $CENTERED ),
		array ( '\[tex\](.*?)\[\\\\tex\]', false ),
		array ( '\[ctex\](.*?)\[\\\\ctex\]', false, $CENTERED ),
	);

?>
