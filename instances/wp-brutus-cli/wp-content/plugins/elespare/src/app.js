import React, { Component } from 'react';
import ReactDOM from 'react-dom';
import App from "./components/app"

class MightyThemesLibraryClass {
    constructor() {
        this.initiatedLibrary = false;
    }

    callback(mutationsList, observer) {

        var _libraryExists = document.getElementById('elespare-library');


        if (_libraryExists !== null && !this.initiatedLibrary) {
            this.initiatedLibrary = true;
            ReactDOM.render(<App />, document.getElementById('elespare-library'));
        } else {

            this.initiatedLibrary = false;
        }


    };

    init() {
        const observer = new MutationObserver(this.callback);
        observer.observe(document, { attributes: true, childList: true, subtree: true });
    };
};

var MightyThemesLibrary = new MightyThemesLibraryClass();
MightyThemesLibrary.init();