import { useState, useEffect } from "react";
import './style/style.css'
import Importbtn from './import-btn'
const { __ } = wp.i18n;
const { apiFetch } = wp;
const App = () => {

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



    const imagepath = AFTLibrary.baseUrl
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
        <div id="ele-templates-modal" className="ele-templates-modal">
            <div className="ele-templates-modal-inner ele-container">

                <div className="ele-templates-modal-header ele-row">
                    <div className="ele-library-templates-header">
                        <div className="ele-logo-area">
                            <div className="brand-logo">
                                <img className="elespare-logo" src={`${imagepath}inc/admin/svg/elespare.png`} height="100" width="100" />
                                {/* <span className="logo-subheading">LIBRARY</span> */}
                            </div>
                        </div>
                        <div className="ele-menu-area">
                            <div className="ele-templates-modal-header-top-tabs">
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
                        <div className="ele-items-area">
                            <div className="ele-templates-modal-header-top-right">
                                <ul className="top-right">
                                    <li className="top-right-list">
                                        <div className="icon" onClick={() => closeModal()}>
                                            <i className="eicon-close"></i>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                <div className="ele-templates-modal-body">
                    <div className="ele-library-templates">
                        {isLoading ? (<Loader />) : (<Importbtn demosData={demos.data} blocks={demos.blocks} navState={navState} />)}
                    </div>
                </div>


            </div>
        </div >
    )
}


export default App