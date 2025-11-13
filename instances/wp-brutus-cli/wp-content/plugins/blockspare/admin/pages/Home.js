import React from 'react'
import Blocks from './Blocks'
import { supportPanel } from '../data/supporPanel'
import Homenotice from "./home-notice"
const { __ } = wp.i18n

const Home = () => {


    return (
        <>

            <div className="content-wrapper dashboard">

                <div className="panel-wrapper first-wrapper">

                    <div className="box-panel feature-panel">
                        <Homenotice />
                        {/* <div>
                            <div>

                                <h1 className="title">{__('Elevate your website instantly with Blockspare!', 'blockspare')}</h1>

                                <h3 className="title">{__('No coding needed - just import, customize, and publish in minutes!', 'blockspare')}</h3>
                                <span className="description">{__('Build Blogs, News, Magazines, and Agency Websites effortlessly using 425+ expert-designed templates. Enjoy limitless style options, animations, and support from our dedicated team!', 'blockspare')}</span>
                                <div className="bs-dashboard__button-group">
                                    <a className="bs-dashboard__button primary" href={blockspare_dashboard.adminPath} target='_blank'>{__('Get Started', 'blockspare')}</a>
                                    <a className="bs-dashboard__button secondary" target="_blank" href="https://www.blockspare.com/starter-templates/">{__('View Demos', 'blockspare')}</a>
                                </div>
                            </div>
                        </div> */}
                        {/* <div className="bs-dashboard__footer">
                        <h2>{__('Browse Ready-to-Use Templates - Launch Your Site in Minutes!', 'blockspare')}</h2>                        
                    </div> */}
                        <div className="bs-dashboard-video">
                            <iframe className="video" width="560" height="315" src="https://www.youtube.com/embed/1dUwERgc6HI?si=r-BSakuzLAq8OXL0" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
                        </div>
                    </div>

                    <Blocks />

                </div>
                <div className="panel-wrapper second-wrapper">
                    <div className="box-panel rating-panel">
                        <a href="https://wordpress.org/support/plugin/blockspare/reviews/?filter=5" target="_blank" rel="noreferrer">
                            <img className="bs-illustration" src={blockspare_dashboard.static_img + "admin/assets/images/rate-us.webp"} />
                        </a>
                    </div>
                    <div className="box-panel support-panel">
                        {supportPanel.map((support) => (
                            <div className="details" key={support.title}>
                                <h2>
                                    {support.icon}
                                    {__(support.title, 'blockspare')}
                                </h2>
                                <span className="description">{__(support.description, 'blockspare')}</span>
                                <a href={support.link_url} target="_blank" rel="noreferrer">{__(support.link_title, 'blockspare')}<i className="fa fa-arrow-right"></i></a>
                            </div>
                        ))}

                    </div>
                </div>
            </div>
        </>
    )
}

export default Home
