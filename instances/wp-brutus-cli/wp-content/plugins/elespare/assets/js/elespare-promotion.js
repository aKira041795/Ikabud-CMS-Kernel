
	parent.document.addEventListener("mousedown", function (e) {
		var widgets = parent.document.querySelectorAll(".elementor-element--promotion");

		if (widgets.length > 0) {
			for (var i = 0; i < widgets.length; i++) {
				if (widgets[i].contains(e.target)) {
					var dialog = parent.document.querySelector("#elementor-element--promotion__dialog");
					var icon = widgets[i].querySelector(".icon > i");

					if (icon.classList.toString().indexOf("elespare") >= 0) {
						dialog.querySelector(".dialog-buttons-action").style.display = "none";
						e.stopImmediatePropagation();
						if (dialog.querySelector(".ele-dialog-buttons-action") === null) {
							var button = document.createElement("a");
							var buttonText = document.createTextNode("Explore More Features");

							button.setAttribute("href", "https://elespare.com/");
							button.setAttribute("target", "_blank");
							button.classList.add(
								"dialog-button",
								"dialog-action",
								"dialog-buttons-action",
								"elementor-button",
								"elementor-button-success",
								"ele-dialog-buttons-action",
								"elespare-promotion-button",
							);
							button.appendChild(buttonText);

							dialog.querySelector(".dialog-buttons-action").insertAdjacentHTML("afterend", button.outerHTML);
						} else {
							dialog.querySelector(".ele-dialog-buttons-action").style.display = "";
						}
					} else {
						dialog.querySelector(".dialog-buttons-action").style.display = "";

						if (dialog.querySelector(".ele-dialog-buttons-action") !== null) {
							dialog.querySelector(".ele-dialog-buttons-action").style.display = "none";
						}
					}

					// stop loop
					break;
				}
			}
		}
	});

