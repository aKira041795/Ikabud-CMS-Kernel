import React from 'react'
import icons from '../../src/assets/icons'
import Container from './Container'
import MobileMenu from './MobileMenu'
import Navs from './Navs'

const { __ } = wp.i18n

const Header = ({current, activeNavHandler}) => {
    return (
        <header>
            <Container>
                <div className="navigation-wrapper">
                    <div className="plugin-logo" onClick={() => activeNavHandler(0)}>
                        {/* <img src={blockspare_dashboard.logo} alt="Blockdpare Logo" /> */}
                    </div>
                    <Navs activeNavHandler={activeNavHandler} current={current} />
                    {/* <div className="bs-dashboare-menu-desktop">
                        <Navs activeNavHandler={activeNavHandler} current={current} />
                    </div> */}
                    <div className="bs-dashboard-upgrade-button links-panel">
                        <a className="bs-dashboard__button bs-upgrade-button" href='https://www.blockspare.com/pricing/' target='_blank'>
                            {icons.diamond}
                            {__('Unlock All Features', 'blockspare')}
                        </a>
                    </div>
                    {/* <div className="bs-dashboare-menu-mobile">
                        <MobileMenu activeNavHandler={activeNavHandler} current={current} />
                    </div> */}
                </div>
            </Container>
        </header>
    )
}

export default Header
