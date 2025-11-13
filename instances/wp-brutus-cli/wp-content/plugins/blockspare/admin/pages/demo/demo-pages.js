import React, { useState, useEffect } from 'react'
const { __ } = wp.i18n;
import Masonry from 'react-masonry-component';
import icons from '../../../src/assets/icons';
import Copycontent from "./copy-content"
import CreateBlockButton from './create-block-button';
import PremiumText from "./premium-text"
const Pages = ({ data, loader }) => {

    const [pages, setPages] = useState([]);
    const [pagetitle, setPagetitle] = useState();
    const [isLoading, setISLoading] = useState(loader)
    const [activeView, setActiveView] = useState('initial');
    const [filterData, setFilterData] = useState([])
    useEffect(() => {
        if (data) {
            const updatedPages = Object.values(data)
                .filter(item => item.type === 'page' || (item.hasOwnProperty('pages') && 'page' !== item.type))
                .sort((a, b) => {
                    const positionA = 'position' in a ? parseInt(a.position) : Number.MAX_SAFE_INTEGER;
                    const positionB = 'position' in b ? parseInt(b.position) : Number.MAX_SAFE_INTEGER;
                    return positionA - positionB;
                });
            setISLoading(loader);
            setPages(updatedPages);
        }
    }, [data, loader]);
    const masonryOptions = {
        transitionDuration: 0,
        percentPosition: true,
    };

    const groupedData = pages.reduce((acc, obj) => {
        const { pages, ...rest } = obj;
        if (!acc[pages]) {
            acc[pages] = [rest];
        } else {
            acc[pages].push(rest);
        }
        return acc;
    }, {});




    const processedData = Object.keys(groupedData).reduce((acc, category) => {
        acc[category] = groupedData[category]
            .sort((a, b) => {
                // Custom sorting logic to move "Home" to the front
                const isFrontpageA = a.is_frontpage === 'true';
                const isFrontpageB = b.is_frontpage === 'true';

                if (isFrontpageA && !isFrontpageB) return -1;
                if (!isFrontpageA && isFrontpageB) return 1;

                // Standard string comparison for other cases based on the 'name' property

                return a.name.localeCompare(b.name);
            })
            .map((item, index) => {
                const { item: remainingItems, ...rest } = item;
                if (index === 0) {
                    return { ...rest, remainingItems };
                } else {
                    return item;
                }
            });
        return acc;
    }, {});


    const handleItemClick = (items, nameInLowerCase, activeView) => {
        setFilterData(items);
        setActiveView('details')
        setPagetitle(nameInLowerCase)

    }

    const toggleBack = () => {
        setActiveView('initial');
    }


    return (

        <div className='bs-layouts-wrappper'>
            {

                (processedData && activeView !== 'details' && isLoading) ?
                    <Masonry elementType={'div'}
                        className={`bs-layout-choices`}
                        options={masonryOptions}
                        disableImagesLoaded={false}
                        updateOnEachImageLoad={false}>
                        {Object.entries(processedData).map(([category, items]) => {
                            const nameInLowerCase = items[0].name.toLowerCase();
                            const imageName = nameInLowerCase.replace(/\s+/g, '-');
                            const folderName = items[0].imagePath
                            const noOfpages = items.length;
                            const pageClass = items[0]?.type === 'page' ? 'bs-layout-page' : '';

                            return (

                                <div className={`bs-layout-design ${pageClass}`}>
                                    <div className="bs-layout-design-inside">
                                        <div className='bs-layout-design-item'>
                                            <div className='bs-layout-insert-button'
                                                onClick={() => handleItemClick(items, nameInLowerCase, activeView)}
                                            >
                                                {/* <a href={items[0].blockLink} target='_blank' > */}
                                                {

                                                    <div className="bs-layout-image-overlay">
                                                        <span className="bs-layout-action-info">
                                                            {icons.view}
                                                            {__('View', 'blockspare')}
                                                        </span>
                                                    </div>

                                                }
                                                <img
                                                    src={blockspare_dashboard.imagePath + folderName + "/" + imageName + ".jpg"}
                                                    alt={category}
                                                />
                                                {/* </a> */}
                                            </div>
                                            <div className='bs-layout-design-info'>
                                                <div className='bs-layout-design-title'>{items[0].name}</div>
                                                <span className="bs-template-count">{noOfpages} {__('Patterns', 'blockspare')}</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>


                            )


                        })

                        }
                    </Masonry> : activeView !== 'details' && <div className="bs-loader-container">
                        <div className="bs-loader"></div>
                    </div>
            }
            {(filterData && isLoading && activeView === 'details') &&
                <>
                    <div className="bs-layout-parent-header">
                        <button className="bs-layout-button bs-back-button" onClick={() => toggleBack()}>
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path d="M22 11H4.414l5.293-5.293a1 1 0 1 0-1.414-1.414l-7 7a1 1 0 0 0 0 1.414l7 7a1 1 0 0 0 1.414-1.414L4.414 13H22a1 1 0 0 0 0-2z" data-original="#000000" />
                            </svg>
                            {__('Back', 'blockspare')}
                        </button>
                        <h3 className="bs-layout-parent-title">{pagetitle}</h3>
                    </div>
                    <Masonry
                        elementType={'div'}
                        className={`bs-layout-choices`}
                        options={masonryOptions}
                        disableImagesLoaded={false}
                        updateOnEachImageLoad={false}
                    >

                        {filterData.map((item) => {
                            const nameInLowerCase = item.name.toLowerCase();
                            const imageName = nameInLowerCase.replace(/\s+/g, '-');
                            const folderName = item.imagePath;
                            const pageClass = item.type === 'page' ? 'bs-layout-page' : '';

                            return (

                                <div className={`bs-layout-design ${pageClass}`}>
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


                        })}
                    </Masonry>
                </>
            }



        </div>
    )

}

export default Pages