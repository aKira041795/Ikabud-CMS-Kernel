import React, { useState, useEffect } from 'react';
import Pages from './demo-pages';
import Headers from './header';
import Footers from './footer';
import Sections from './sections';
import Countdemo from "./count-demo"
const { __ } = wp.i18n;

const Designs = () => {
    const [navState, setNavState] = useState("pages");
    const [demoData, setDemoData] = useState([])
    const [isLoading, setISLoading] = useState(false)
    useEffect(() => {
        wp.apiFetch({
            method: 'GET',
            path: '/blockspare/v1/layouts/all?filter=allowed',
        })
            .then(async (components) => {
                setDemoData(components);
                setISLoading(true)
            })
            .catch(error => {
                console.error('Error fetching data:', error);
                // Handle the error, e.g., set an error state or show a message to the user.
            });
    }, [navState])
    const handleNavStateChange = (tab) => {
        // sessionStorage.setItem("nav", tab);
        setNavState(tab);
    };

    return (
        <div className="aft-dashboard-main-section">
            <div className="bs-layout-tab-content settings-tab-content-wrapper">
                <aside className='bs-layout-sidebar'>
                    <div className="bs-layout-category-list">
                        <div
                            className={`bs-layout-category ${navState == "pages" ? "bs-layout-selected-category" : ""
                                }`}
                            onClick={(e) => handleNavStateChange("pages")}
                        >
                            {__("All Patterns", 'blockspare')}
                            <Countdemo type='page' data={demoData} />
                        </div>
                        <div
                            className={`bs-layout-category ${navState == "sections" ? "bs-layout-selected-category" : ""
                                }`}
                            onClick={(e) => handleNavStateChange("sections")}
                        >
                            {__("Sections", 'blockspare')}
                            <Countdemo type='section' data={demoData} />
                        </div>

                        <div
                            className={`bs-layout-category ${navState == "headers" ? "bs-layout-selected-category" : ""
                                }`}
                            onClick={(e) => handleNavStateChange("headers")}
                        >
                            {__("Headers", 'blockspare')}
                            <Countdemo type='header' data={demoData} />
                        </div>

                        <div
                            className={`bs-layout-category ${navState == "footers" ? "bs-layout-selected-category" : ""
                                }`}
                            onClick={(e) => handleNavStateChange("footers")}
                        >
                            {__("Footers", 'blockspare')}
                            <Countdemo type='footer' data={demoData} />
                        </div>


                    </div>
                </aside>
                {navState == "pages" && <Pages data={demoData} loader={isLoading} />}
                {navState == "sections" && <Sections data={demoData} />}
                {navState == "headers" && <Headers data={demoData} />}
                {navState == "footers" && <Footers data={demoData} />}


            </div>
        </div>
    )

}

export default Designs