import React from 'react';
import ReactDOM from 'react-dom';
import Dashboard from "./dashboard"


document.addEventListener('DOMContentLoaded', () => {
    var root_id = "elespare-demo-list"
    if ('undefined' !== typeof document.getElementById(root_id) && null !== document.getElementById(root_id)) {
        ReactDOM.render(<Dashboard />, document.getElementById(root_id));
    }
});