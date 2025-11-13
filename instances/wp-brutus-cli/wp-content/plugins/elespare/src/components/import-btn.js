import { useState, useEffect } from "react";
const { apiFetch } = wp;
const { __ } = wp.i18n;
import Loader from "./loader"
import Masonry from 'react-masonry-component';
const imagepath = AFTLibrary.externalUrl
const Importbtn = ({ demosData, blocks, navState }) => {

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
                                        { }
                                        <div className="template-item-figure">
                                            <div className="ele-library-template-body">
                                                <img src={`${proUrl}/${data.type}/${data.image}/${data.content}.png`} alt="template-thumbnail" />
                                                {(isProExists !== true) ? (
                                                    <a onClick={(e) => importJsonData(e, data.content, data.type, '')} className="ele-library-template-preview">
                                                        <span><i className="eicon-file-download" aria-hidden="true"></i> {__('Import', 'elespare')}</span>
                                                    </a>
                                                ) : ""}
                                            </div>
                                            <div className="ele-library-template-footer">
                                                <h3>{data.title}</h3>
                                                <a href={data.url} target="_blank" className='elespare-immport-btn'>
                                                    <i className="eicon-zoom-in-bold" aria-hidden="true"></i><span>{__('View', 'elespare')}</span>
                                                </a>
                                                {isProExists === true &&
                                                    <span className='elespare-immport-btn'>{__('Pro', 'elespare')}</span>

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
                                                {(isProExists !== true) ? (
                                                    <a onClick={(e) => importJsonData(e, data.content, data.type, '')} className="ele-library-template-preview">
                                                        <span><i className="eicon-file-download" aria-hidden="true"></i> {__('Import', 'elespare')}</span>
                                                    </a>
                                                ) : ""}
                                            </div>
                                            <div className="ele-library-template-footer">
                                                <h3>{data.title}</h3>

                                                <a href={data.url} target="_blank" className='elespare-immport-btn'>
                                                    <i className="eicon-zoom-in-bold" aria-hidden="true"></i>
                                                    <span>{__('View', 'elespare')}</span>
                                                </a>
                                                {isProExists === true &&
                                                    <span className='elespare-immport-btn'>{__('Pro', 'elespare')}</span>

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
                                                {(isProExists !== true) ? (
                                                    <a onClick={(e) => importJsonData(e, data.content, data.type, '')} className="ele-library-template-preview">
                                                        <span><i className="eicon-file-download" aria-hidden="true"></i> {__('Import', 'elespare')}</span>
                                                    </a>
                                                ) : ""}
                                            </div>
                                            <div className="ele-library-template-footer">
                                                <h3>{data.title}</h3>
                                                <a href={data.url} target="_blank" className='elespare-immport-btn'>
                                                    <i className="eicon-zoom-in-bold" aria-hidden="true"></i>
                                                    <span>{__('View', 'elespare')}</span>
                                                </a>
                                                {isProExists === true &&
                                                    <span className='elespare-immport-btn'>{__('Pro', 'elespare')}</span>

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
                                        {(isProExists !== true) ? (
                                            <a onClick={(e) => importJsonData(e, block.content, block.type, blockFolder[0])} className="ele-library-template-preview">
                                                <span><i className="eicon-file-download" aria-hidden="true"></i> {__('Import', 'elespare')}</span>
                                            </a>
                                        ) : ""}
                                    </div>
                                    <div className="ele-library-template-footer">
                                        <h3>{block.title}</h3>
                                        <a href={block.url} target="_blank" className='elespare-immport-btn'>
                                            <i className="eicon-zoom-in-bold" aria-hidden="true"></i>
                                            <span>{__('View', 'elespare')}</span>
                                        </a>
                                        {isProExists === true &&
                                            <span className='elespare-immport-btn'>{__('Pro', 'elespare')}</span>

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

export default Importbtn