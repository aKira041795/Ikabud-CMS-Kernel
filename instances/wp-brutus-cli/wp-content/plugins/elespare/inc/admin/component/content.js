import { useState, useEffect } from "react";
const { apiFetch } = wp;
const { __ } = wp.i18n;
import Loader from "./../../../src/components/loader"
import Masonry from 'react-masonry-component';
const imagepath = ELELibrary.externalUrl
const Content = ({ demosData, blocks, navState }) => {
    const [preState, setPreState] = useState(false);
    const itemsPerPage = 10;
    const [visibleItems, setVisibleItems] = useState(itemsPerPage);
    const [tmpl, setTmpl] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const importJsonData = async (e, content, type, folderPath) => {
        setIsLoading(true);

        let tmpl = await apiFetch({
            path: 'elespare/v1/import-template?elepsapre_templates_kit=' + content + '&type=' + type + '&folder_path=' + folderPath,
            method: "POST"
        }).then((tmpl) => {
            setTmpl(tmpl)
            window.aftModal.hide()
            elementor.previewView.addChildModel(tmpl.data.template.content)
            $e.run('document/save/default');

            elementor.notifications.showToast({
                message: elementor.translate('Template Imported!')
            });

            setIsLoading(false);
        })
    }

    const loadMore = () => {
        setVisibleItems(prevVisibleItems => prevVisibleItems + itemsPerPage);
    };

    const masonryOptions = {
        transitionDuration: 0,
        percentPosition: true,
    };

    const handleBtnStateChange = (e, preState) => {
        e.preventDefault()
        setPreState(preState)
    }

    return (
        <>
            <Masonry
                elementType={'ul'}
                className={`ele-templates-container`}
                options={masonryOptions}
                disableImagesLoaded={false}
                updateOnEachImageLoad={false}
            >

                {

                    isLoading ? <Loader /> : demosData && demosData.map((data, i) => {
                        let otherFolder = data.image.split('/');

                        const isProExists = data.hasOwnProperty('ispro');
                        const isFlexLayout = data.hasOwnProperty('flex');

                        let proUrl = '';
                        if (isProExists === true) {
                            proUrl = imagepath.replace('/free', '/');

                        } else {
                            proUrl = imagepath
                        }


                        return (

                            <>



                                {

                                    navState === 'home' && data.type === 'homepage' && <li>

                                        <div className="template-item-figure">
                                            <div className="ele-library-template-body">

                                                <img src={`${proUrl}/${data.type}/${data.image}/${data.content}.png`} alt="template-thumbnail" />
                                                <a onClick={(e) => handleBtnStateChange(e, true)} data-src={data.url} data-name={data.title} className="elespare-immport-btn elespare-open-iframe" >
                                                    <i className="eicon-zoom-in-bold" aria-hidden="true"></i><span>{__('View', 'elespare')}</span>
                                                </a>
                                            </div>
                                            <div className="ele-library-template-footer">
                                                <h3>{data.title}</h3>
                                                

                                                {/* <a href={data.url} target="_blank" className='elespare-immport-btn'>
                                                    <i className="eicon-zoom-in-bold" aria-hidden="true"></i><span>{__('View', 'elespare')}</span>
                                                </a> */}
                                                {(isProExists !== true) ? (
                                                    <a href={ELELibrary.newPageUrl + "=" + data.content + "&page_title=" + data.title + "&action=elementor" + "&type=" + data.type + "&folder_path="} target="_blank" className="elespare-immport-btn">
                                                        <span className="dashicons dashicons-admin-appearance">{__('Use Template', 'elespare')}</span>
                                                    </a>
                                                ) : ''}



                                                {isProExists === true &&
                                                    <a href={"https://elespare.com/pricing/"} target="_blank" className="elespare-immport-btn">
                                                        <span className="dashicons dashicons-superhero-alt">{__('Upgrade', 'elespare')}</span>
                                                    </a>

                                                }

                                            </div>
                                        </div>

                                    </li>
                                }
                                {
                                    navState === 'header' && data.type === 'header' && <li>
                                        <div className="template-item-figure">
                                            <div className="ele-library-template-body">

                                                <img src={`${proUrl}/${data.type}/${data.image}/${data.content}.png`} alt="template-thumbnail" />
                                                <a onClick={(e) => handleBtnStateChange(e, true)} data-src={data.url} data-name={data.title} className="elespare-immport-btn elespare-open-iframe" >
                                                    <i className="eicon-zoom-in-bold" aria-hidden="true"></i><span>{__('View', 'elespare')}</span>
                                                </a>
                                            </div>
                                            <div className="ele-library-template-footer">
                                                <h3>{data.title}</h3>


                                                {(isProExists !== true) ? (
                                                    <a href={ELELibrary.newPageUrl + "=" + data.content + "&page_title=" + data.title + "&action=elementor" + "&type=" + data.type + "&folder_path="} target="_blank" className="elespare-immport-btn">
                                                        <span className="dashicons dashicons-admin-appearance">{__('Use Template', 'elespare')}</span>
                                                    </a>
                                                ) : ""}
                                                {isProExists === true &&
                                                    <a href={"https://elespare.com/pricing/"} target="_blank" className="elespare-immport-btn">
                                                        <span className="dashicons dashicons-superhero-alt">{__('Upgrade', 'elespare')}</span>
                                                    </a>
                                                }
                                            </div>
                                        </div>

                                    </li>
                                }
                                {
                                    navState === 'footer' && data.type === 'footer' && <li>

                                        <div className="template-item-figure">
                                            <div className="ele-library-template-body">

                                                <img src={`${proUrl}/${data.type}/${data.image}/${data.content}.png`} alt="template-thumbnail" />
                                                <a onClick={(e) => handleBtnStateChange(e, true)} data-src={data.url} data-name={data.title} className="elespare-immport-btn elespare-open-iframe" >
                                                    <i className="eicon-zoom-in-bold" aria-hidden="true"></i><span>{__('View', 'elespare')}</span>
                                                </a>
                                            </div>
                                            <div className="ele-library-template-footer">
                                                <h3>{data.title}</h3>

                                                {(isProExists !== true) ? (
                                                    <a href={ELELibrary.newPageUrl + "=" + data.content + "&page_title=" + data.title + "&action=elementor" + "&type=" + data.type + "&folder_path="} target="_blank" className="elespare-immport-btn">
                                                        <span className="dashicons dashicons-admin-appearance">{__('Use Template', 'elespare')}</span>
                                                    </a>

                                                ) : ""}
                                                {isProExists === true &&
                                                    <a href={"https://elespare.com/pricing/"} target="_blank" className="elespare-immport-btn">
                                                        <span className="dashicons dashicons-superhero-alt">{__('Upgrade', 'elespare')}</span>
                                                    </a>
                                                }
                                            </div>

                                        </div>

                                    </li>
                                }



                            </>
                        )

                    })


                }
                {
                    isLoading ? <Loader /> : blocks && blocks.slice(0, visibleItems).map((block, i) => {
                        let blockFolder = block.image.split('/');
                        const isProExists = block.hasOwnProperty('ispro');

                        let proUrl = '';
                        if (isProExists === true) {
                            proUrl = imagepath.replace('/free', '/');

                        } else {
                            proUrl = imagepath
                        }

                        return (

                            navState === 'blocks' && block.type === 'blocks' && <li>

                                <div className="template-item-figure">
                                    <div className="ele-library-template-body">

                                        <img src={`${proUrl}/${block.type}/${block.image}/${block.content}.png`} alt="template-thumbnail" />
                                        {/* <a onClick={(e) => importJsonData(e, block.content, block.type, blockFolder[0])} className="ele-library-template-preview">
                                            <span><i className="eicon-plus" aria-hidden="true"></i> {__('Create Page', 'elespare')}</span>
                                        </a> */}


                                        <a onClick={(e) => handleBtnStateChange(e, true)} data-src={block.url} data-name={block.title} className="elespare-immport-btn elespare-open-iframe" >
                                            <i className="eicon-zoom-in-bold" aria-hidden="true"></i><span>{__('View', 'elespare')}</span>
                                        </a>
                                    </div>
                                    <div className="ele-library-template-footer">
                                        <h3>{block.title}</h3>
                                        {(isProExists !== true) ? (
                                            <a href={ELELibrary.newPageUrl + "=" + block.content + "&page_title=" + block.title + "&type=" + block.type + "&action=elementor&folder_path=" + blockFolder[0]} target="_blank" className="elespare-immport-btn">
                                                <span className="dashicons dashicons-admin-appearance">{__('Use Template', 'elespare')}</span>
                                            </a>
                                        ) : ""}
                                        {isProExists === true &&
                                            <a href={"https://elespare.com/pricing/"} target="_blank" className="elespare-immport-btn">
                                                <span className="dashicons dashicons-superhero-alt">{__('Upgrade', 'elespare')}</span>
                                            </a>
                                        }
                                    </div>
                                </div>

                            </li>

                        )

                    })
                }
            </Masonry>
            <>
                {navState === 'blocks' && visibleItems < blocks.length && (
                    <button onClick={loadMore} className="ele-load-more">Load More</button>
                )}
            </>
        </>
    )
}

export default Content