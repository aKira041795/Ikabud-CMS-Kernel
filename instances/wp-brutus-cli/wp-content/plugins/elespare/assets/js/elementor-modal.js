/* global elementor, elementorCommon */
/* eslint-disable */
"undefined" != typeof jQuery && function ($) {
    $(function () {
        function showStuff() {
            if (elementorCommon) {

                window.aftModal || (window.aftModal = elementorCommon.dialogsManager.createWidget(
                    "lightbox", {
                    id: "elespare-library-modal",
                    headerMessage: !1,
                    message: "",
                    hide: {
                        auto: !1,
                        onClick: !1,
                        onOutsideClick: !1,
                        onOutsideContextMenu: !1,
                        onBackgroundClick: !0
                    },
                    position: {
                        my: "center",
                        at: "center"
                    },
                    onShow: function () {
                        var content = window.aftModal.getElements("content");
                        if (content.html() === '') {
                            content.html('<div id="elespare-library"></div>');
                        }
                    },
                    onHide: function () { }
                }), window.aftModal.getElements("header").remove(),
                    window.aftModal.getElements("message").append(window.aftModal.addElement("content"))),
                    window.aftModal.show()
            }
        }
        window.aftModal = null;
        var btn = $("#tmpl-elementor-add-section");
        if (0 < btn.length) {
            var btnText = btn.text();
            btnText = btnText.replace('<div class="elementor-add-section-drag-title', '<div class="elementor-add-section-area-button elementor-add-elespare-button" title="elespare Library"> <i class="eicon-folder"></i> </div><div class="elementor-add-section-drag-title'),
                btn.text(btnText), elementor.on("preview:loaded", function () {
                    $(elementor.$previewContents[0].body).on("click", ".elementor-add-elespare-button", showStuff)
                })
        }
    })

}(jQuery);