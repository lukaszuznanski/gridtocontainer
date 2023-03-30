document.addEventListener("DOMContentLoaded", function() {
	document.querySelectorAll('.js-columnId').forEach( function(element) {
		element.addEventListener('keyup', function() {
			let checkbox = this.attributes.getNamedItem('data-checkbox').value;
			document.getElementById(checkbox).checked = this.value === '';
		});
	})
});
