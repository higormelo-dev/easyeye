

! function() {
	var t = sessionStorage.getItem("__THEME_CONFIG__"),
		e = document.getElementsByTagName("html")[0],
		i = {
			theme: "light",
			nav: "vertical",
 		color: {
 			color: "info"
 		},
			layout: {
				mode: "fluid"
			},
 		topbar: {
 			color: "bg-light"
 		},
 		menu: {
 			color: "sidebar6"
 		},
			sidenav: {
				size: "default",
				user: !1
			}
		};
var config;
    var html = document.getElementsByTagName("html")[0];

    config = Object.assign({}, i);

    var attrTheme = html.getAttribute("data-bs-theme");
    config.theme = attrTheme !== null ? attrTheme : i.theme;

    var attrLayoutMode = html.getAttribute("data-layout");
    config.layout.mode = attrLayoutMode !== null ? attrLayoutMode : i.layout.mode;

    var attrColor = html.getAttribute("data-color");
    config.color.color = attrColor !== null ? attrColor : i.color.color;

    var attrTopbar = html.getAttribute("data-topbar");
    config.topbar.color = attrTopbar !== null ? attrTopbar : i.topbar.color;

    var attrSidenavSize = html.getAttribute("data-layout");
    config.sidenav.size = attrSidenavSize !== null ? attrSidenavSize : i.sidenav.size;

    var attrSidenavUser = html.getAttribute("data-sidenav-user");
    config.sidenav.user = attrSidenavUser !== null ? attrSidenavUser : i.sidenav.user;

    var attrMenuColor = html.getAttribute("data-sidebar");
    config.menu.color = attrMenuColor !== null ? attrMenuColor : i.menu.color;

    window.defaultConfig = JSON.parse(JSON.stringify(config));

    if (null !== t) {
        config = JSON.parse(t);
    }

    window.config = config;
		if ("vertical" == config.nav) {
			let t = config.sidenav.size;
			window.innerWidth <= 767 ? t = "full-width" : 767 <= window.innerWidth && window.innerWidth <= 1140 && "full-width" !== self.config.sidenav.size && "hidden" !== self.config.sidenav.size && (t = "condensed"), e.setAttribute("data-layout", t), config.sidenav.user && "true" === config.sidenav.user.toString() ? e.setAttribute("data-sidenav-user", !0) : e.removeAttribute("data-sidenav-user")
		}
		e.setAttribute("data-bs-theme", config.theme), e.setAttribute("data-sidebar", config.menu.color), e.setAttribute("data-topbar", config.topbar.color), e.setAttribute("data-color", config.color.color);
}();
class ThemeCustomizer {
	constructor() {
		this.html = document.getElementsByTagName("html")[0], this.config = {}, this.defaultConfig = window.config
	}
	initConfig() {
		this.defaultConfig = JSON.parse(JSON.stringify(window.defaultConfig)), this.config = JSON.parse(JSON.stringify(window.config)), this.setSwitchFromConfig()
	}
	changeMenuColor(e) {
		this.config.menu.color = e, this.html.setAttribute("data-sidebar", e), this.setSwitchFromConfig()
	}
	changeLeftbarSize(e, t = !0) {
		this.html.setAttribute("data-layout", e);

		if (document.body) {
			if (e === "mini") {
			document.body.classList.add("mini-sidebar");
			} else {
			document.body.classList.remove("mini-sidebar");
			}
		}

		t && (this.config.sidenav.size = e, this.setSwitchFromConfig());
	}
	changeThemeColor(e) {
		this.config.color.color = e, this.html.setAttribute("data-color", e), this.setSwitchFromConfig()
	}
	changeLayoutColor(e) {
		this.config.theme = e, this.html.setAttribute("data-bs-theme", e), this.setSwitchFromConfig()
	}
	changeTopbarColor(e) {
		this.config.topbar.color = e, this.html.setAttribute("data-topbar", e), this.setSwitchFromConfig()
	}
	resetTheme() {
		this.config = JSON.parse(JSON.stringify(window.defaultConfig)), this.changeMenuColor(this.config.menu.color), this.changeLeftbarSize(this.config.sidenav.size), this.changeLayoutColor(this.config.theme), this.changeTopbarColor(this.config.topbar.color), this.changeThemeColor(this.config.color.color), this._adjustLayout()
	}
	initSwitchListener() {
		var a = this,
			e = (document.querySelectorAll("input[name=data-sidebar]").forEach(function(t) {
				t.addEventListener("change", function(e) {
					a.changeMenuColor(t.value)
				})
			}), document.querySelectorAll("input[name=data-color]").forEach(function(t) {
				t.addEventListener("change", function(e) {
					a.changeThemeColor(t.value)
				})
			}), document.querySelectorAll("input[name=data-layout]").forEach(function(t) {
				t.addEventListener("change", function(e) {
					a.changeLeftbarSize(t.value)
				})
			}), document.querySelectorAll("input[name=data-bs-theme]").forEach(function(t) {
				t.addEventListener("change", function(e) {
					a.changeLayoutColor(t.value)
				})
			}), document.querySelectorAll("input[name=data-topbar]").forEach(function(t) {
				t.addEventListener("change", function(e) {
					a.changeTopbarColor(t.value)
				})
			}), document.getElementById("light-dark-mode")),
			e = (e && e.addEventListener("click", function(e) {
				"light" === a.config.theme ? a.changeLayoutColor("dark") : a.changeLayoutColor("light")
			}), document.querySelector("#reset-layout")),
			e = (e && e.addEventListener("click", function(e) {
				a.resetTheme()
			}), document.querySelector(".sidenav-toggle-button")),
			e = (e && e.addEventListener("click", function() {
				var e = a.config.sidenav.size,
					t = a.html.getAttribute("data-layout", e);
				"full-width" === t ? a.showBackdrop() : "hidden" == e ? "hidden" === t ? a.changeLeftbarSize("hidden" == e ? "default" : e, !1) : a.changeLeftbarSize("hidden", !1) : "condensed" === t ? a.changeLeftbarSize("condensed" == e ? "default" : e, !1) : a.changeLeftbarSize("condensed", !1), a.html.classList.toggle("sidebar-enable")
			}), document.querySelector(".button-close-fullsidebar"));
		e && e.addEventListener("click", function() {
			a.html.classList.remove("sidebar-enable"), a.hideBackdrop()
		}), document.querySelectorAll(".button-sm-hover").forEach(function(e) {
			e.addEventListener("click", function() {
				var e = a.config.sidenav.size;
				"sm-hover-active" === a.html.getAttribute("data-layout", e) ? a.changeLeftbarSize("hover-view", !1) : a.changeLeftbarSize("sm-hover-active", !1)
			})
		})
	}
	showBackdrop() {
		const e = document.createElement("div"),
			t = (e.id = "custom-backdrop", e.classList = "offcanvas-backdrop fade show", document.body.appendChild(e), document.body.style.overflow = "hidden", 767 < window.innerWidth && (document.body.style.paddingRight = "15px"), this);
		e.addEventListener("click", function(e) {
			t.html.classList.remove("sidebar-enable"), t.hideBackdrop()
		})
	}
	hideBackdrop() {
		var e = document.getElementById("custom-backdrop");
		e && (document.body.removeChild(e), document.body.style.overflow = null, document.body.style.paddingRight = null)
	}
	initWindowSize() {
		var t = this;
		window.addEventListener("resize", function(e) {
			t._adjustLayout()
		})
	}
	_adjustLayout() {
		var e = this;
		window.innerWidth <= 767.98 ? e.changeLeftbarSize("full-width", !1) : 767 <= window.innerWidth && window.innerWidth <= 1140 ? "full-width" !== e.config.sidenav.size && "hidden" !== e.config.sidenav.size && ("hover-view" === e.config.sidenav.size ? e.changeLeftbarSize("condensed") : e.changeLeftbarSize("condensed", !1)) : (e.changeLeftbarSize(e.config.sidenav.size))
	}
	setSwitchFromConfig() {
		sessionStorage.setItem("__THEME_CONFIG__", JSON.stringify(this.config)), document.querySelectorAll(".right-bar input[type=checkbox]").forEach(function(e) {
			e.checked = !1
		});
		var e, t, a, n, i, o = this.config;
		o && (e = document.querySelector("input[type=radio][name=data-layout][value=" + o.nav + "]"), t = document.querySelector("input[type=radio][name=data-bs-theme][value=" + o.theme + "]"), a = document.querySelector("input[type=radio][name=data-color][value=" + o.color.color + "]"), n = document.querySelector("input[type=radio][name=data-topbar][value=" + o.topbar.color + "]"), i = document.querySelector("input[type=radio][name=data-sidebar][value=" + o.menu.color + "]"), o = document.querySelector("input[type=radio][name=data-layout][value=" + o.sidenav.size + "]"), e && (e.checked = !0), t && (t.checked = !0), a && (a.checked = !0), n && (n.checked = !0), i && (i.checked = !0), o && (o.checked = !0))
	}
	init() {
		this.initConfig(), this.initSwitchListener(), this.initWindowSize(), this._adjustLayout(), this.setSwitchFromConfig()
	}
}
document.addEventListener("DOMContentLoaded", function(e) {
	(new ThemeCustomizer).init()
});

