import { useState, useEffect } from "react";

import Content from "./content"
const { __ } = wp.i18n;
const { apiFetch } = wp;
const Dashboard = () => {

    const [navState, setNavState] = useState('home')
    const [demos, setDemo] = useState([]);
    const [currentPage, setCurrentPage] = useState(1);
    const [isLoading, setIsLoading] = useState(false)

    const handleNavStateChange = (tab) => {

        setNavState(tab)
        setCurrentPage(1); // Reset current page when changing tabs
        getDemoNav(tab, 1);
    }





    useEffect(() => {
        getDemoNav(navState, currentPage);
    }, [currentPage]);
    const getDemoNav = async (tab, page) => {

        setIsLoading(true)
        let demos = await apiFetch({
            path: `elespare/v1/template-lists?nav=${tab}&page=${page}`,
            method: "GET"
        });


        setDemo(demos);
        setIsLoading(false)

    };

    const closeModal = () => {
        window.aftModal.hide();

    }



    const imagepath = ELELibrary.baseUrl

    const Loader = () => {

        return (
            <div className="ele-loader-box" >
                <div className="ele-loader">
                    <img src={`${imagepath}src/components/images/loader.svg`} />
                </div>
                <p>{__('Loading Template Kits', 'elespare')}</p>
            </div>
        )
    }

    return (
        <div id="ele-templates-demo-lists" className="ele-templates-demo-lists">
            <div className="ele-templates-demo-lists-inner ele-container">

                <div className="ele-templates-demo-lists-header ele-row">
                    <div className="ele-library-templates-header">
                        <div className="ele-logo-area">
                            <div className="brand-logo">
                                <img className="elespare-logo" src={`${ELELibrary.logo}`} height="100" width="100" />
                                {/* <span className="logo-subheading">LIBRARY</span> */}
                            </div>
                        </div>
                        <div className="ele-menu-area">
                            <div className="ele-templates-demo-lists-header-top-tabs">
                                <>
                                    <ul>
                                        <li className={`navbar-item settings ${navState == 'home' ? 'active' : 'inactive'}`} onClick={e => handleNavStateChange('home')} ><a>{__('Homepage', 'elespare')}</a></li>
                                        <li className={`navbar-item settings ${navState == 'header' ? 'active' : 'inactive'}`} onClick={e => handleNavStateChange('header')} ><a>{__('Header', 'elespare')}</a></li>
                                        <li className={`navbar-item settings ${navState == 'footer' ? 'active' : 'inactive'}`} onClick={e => handleNavStateChange('footer')} ><a>{__('Footer', 'elespare')}</a></li>
                                        <li className={`navbar-item settings ${navState == 'blocks' ? 'active' : 'inactive'}`} onClick={e => handleNavStateChange('blocks')} ><a>{__('Blocks', 'elespare')}</a></li>

                                    </ul>
                                </>

                            </div>
                        </div>

                    </div>
                    <div className="ele-upgrade">
                        <a href="https://elespare.com/pricing/" target="_blank">{__('Upgrade', 'elspare')}</a>
                    </div>
                </div>
                <div className="ele-templates-demo-lists-body">
                    <div className="ele-library-templates">
                        {isLoading ? (<Loader />) : (<Content demosData={demos.data} blocks={demos.blocks} navState={navState} />)}
                    </div>
                </div>


            </div>
        </div >
    )
}


export default Dashboard