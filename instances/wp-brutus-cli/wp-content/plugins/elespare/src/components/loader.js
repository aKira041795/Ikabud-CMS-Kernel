import React, { Component } from "react"

const Loader = () => {
    const imagepath = AFTLibrary.baseUrl
    return (
        <div className="ele-loader-box" >
            <div className="ele-loader">
                <img src={`${imagepath}src/components/images/loader.svg`} />
            </div>
            <p>Importing Template Please wait</p>

        </div>
    )

}

export default Loader;