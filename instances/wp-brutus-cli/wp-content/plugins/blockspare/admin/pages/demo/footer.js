import React, { useState, useEffect } from 'react'
const { __ } = wp.i18n;
import Copycontent from './copy-content'
import Masonry from 'react-masonry-component';
import CreateBlockButton from './create-block-button';
import PremiumText from "./premium-text"
import icons from '../../../src/assets/icons';
const Footers = ({ data }) => {
    const [isLoading, setISLoading] = useState(false)
    const [visibleItems, setVisibleItems] = useState(10)
    const [footers, setFooters] = useState([]);
    useEffect(() => {
        if (data) {
            const footers = Object.values(data)
                .filter(item => item.type === 'footer')
                .sort((a, b) => {
                    if (a.name < b.name) {
                        return -1;
                    }
                    if (a.name > b.name) {
                        return 1;
                    }
                    return 0;
                });
            setISLoading(true)
            setFooters(footers)
        }
    }, [data]);
    const masonryOptions = {
        transitionDuration: 0,
        percentPosition: true,
    };

    useEffect(() => {
        const handleScroll = () => {
            const container = document.getElementById('bs-blockpages');
            const scrollY = container.scrollTop;
            const containerHeight = container.clientHeight;
            const contentHeight = container.scrollHeight;

            if (scrollY + containerHeight >= contentHeight - 200) {
                // Adjust the value (200) as needed to trigger the load more function earlier or later
                loadVisibleItems();
            }
        };

        const container = document.getElementById('bs-blockpages');
        container.addEventListener('scroll', handleScroll);

        return () => {
            container.removeEventListener('scroll', handleScroll);
        };
    }, [visibleItems]);


    const loadVisibleItems = () => {
        const itemsPerPage = 10;
        setVisibleItems(prevVisibleItems => prevVisibleItems + itemsPerPage);
    };

    return (

        <div className='bs-layouts-wrappper' id="bs-blockpages">
            {isLoading ? <Masonry
                elementType={'div'}
                className={`bs-layout-choices`}
                options={masonryOptions}
                disableImagesLoaded={false}
                updateOnEachImageLoad={false}
            >
                {footers &&
                    footers.slice(0, visibleItems).map((item, i) => {
                        const nameInLowerCase = item.name.toLowerCase();
                        const imageName = nameInLowerCase.replace(/\s+/g, '-');
                        const folderName = item.imagePath
                        return (

                            <div className='bs-layout-design'>
                                <div className="bs-layout-design-inside">
                                    <div className='bs-layout-design-item'>
                                        <a href={item.content === 'https://www.blockspare.com/' ? 'javascript:void(0);' : item.blockLink} target={item.content === 'https://www.blockspare.com/' ? '_self' : '_blank'} className="bs-layout-insert-button" >
                                            {
                                                item.content === 'https://www.blockspare.com/' ? (
                                                    <PremiumText link={item.blockLink} />
                                                ) : (
                                                    <div className="bs-layout-image-overlay">
                                                        <span className="bs-layout-action-info">
                                                            {icons.view}
                                                            {__('View', 'blockspare')}
                                                        </span>
                                                    </div>
                                                )
                                            }
                                            <img
                                                src={blockspare_dashboard.imagePath + folderName + "/" + imageName + ".jpg"}
                                                alt={item.name}
                                            />
                                        </a>
                                        <div className='bs-layout-design-info'>
                                            <div className='bs-layout-design-title'>{item.name}</div>
                                            {item.content !== 'https://www.blockspare.com/' &&
                                                <div className='bs-layout-actions'>
                                                    <CreateBlockButton href={blockspare_dashboard.newPageUrl + "=" + item.key + "&post_title=" + item.name} />
                                                    {/* <a target='_blank' href={blockspare_dashboard.newPageUrl + "=" + item.key + "&post_title=" + item.name}>{__('Create block in new page', 'blockspare')}</a> */}
                                                    <Copycontent contentdata={item.content} />
                                                </div>
                                            }
                                        </div>
                                    </div>
                                </div>
                            </div>

                        )


                    })
                }
            </Masonry> : <div className="bs-loader-container">
                <div className="bs-loader"></div>
            </div>}
        </div>
    )

}

export default Footers