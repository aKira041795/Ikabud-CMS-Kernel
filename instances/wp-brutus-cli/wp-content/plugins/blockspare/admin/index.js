import React from 'react';
import ReactDOM from 'react-dom';
import Dashboard from "./Dashboard"
import './assets/styles/style.scss'

document.addEventListener('DOMContentLoaded', () => {
    var root_id = "bs-dashboard"
    if ('undefined' !== typeof document.getElementById(root_id) && null !== document.getElementById(root_id)) {
        ReactDOM.render(<Dashboard />, document.getElementById(root_id));
    }
});