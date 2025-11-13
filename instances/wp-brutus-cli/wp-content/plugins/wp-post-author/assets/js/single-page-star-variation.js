import ReactDOM from 'react-dom'
import ReactStars from 'react-stars'
import { transitions, positions, Provider as AlertProvider } from 'react-alert'
import AlertTemplate from 'react-alert-template-basic'
// function Singlepagestar({ count, avg, post_rating_type, rating_color_back, rating_color_front, show_avg, show_star_type, show_votes }) {



//     return (<>

//         <ReactStars className="awpa-pro-rating-review "
//             key={Math.random() * 100000}
//             count={post_rating_type}
//             value={avg}
//             size={22}
//             half={true}
//             edit={false}
//             color1={rating_color_back}
//             color2={rating_color_front}
//         />
//         <span className='rating-avg'>{show_avg ? avg : ''}</span>
//         <span className='rating-type'>{show_star_type ? post_rating_type : ''} </span>
//         <span className='rating-votes'> {show_votes ? count + ' reviews' : ''}</span>

//     </>)


// }

// export default Singlepagestar

document.addEventListener('DOMContentLoaded', () => {
    var root_id = "awpa-single-post-star-variation"
    var elements = document.getElementsByClassName(root_id)

    if (elements) {
        for (let index = 0; index < elements.length; index++) {
            const element = elements[index];
            var data = element.getAttribute('attributes')
            //var post_rating_type = element.getAttribute('rating-type')

            var attributes = JSON.parse(data)


            let count = (attributes.length > 0) ? attributes[0].count : 0
            let avg = (attributes.length > 0) ? attributes[0].avg : 0

            let post_rating_type = element.getAttribute('rating_type')
            let rating_color_back = element.getAttribute('rating_color_back')
            let rating_color_front = element.getAttribute('rating_color_front')
            let show_star_rating = element.getAttribute('show_star_rating')
            let show_avg = element.getAttribute('show_avg')
            let star_size = element.getAttribute('star_size')
            let show_votes = element.getAttribute('show_votes')
            let star_sizes;
            if (star_size === 'x-large') {
                star_sizes = 32
            }else if (star_size === 'large') {
                star_sizes = 24
            } else if (star_size === 'medium') {
                star_sizes = 16
            } else if (star_size === 'small') {
                star_sizes = 14
            } else if (star_size === 'x-small') {
                star_sizes = 12
            } else {
                star_sizes = 20
            }

            ReactDOM.render(<AlertProvider>

                {show_star_rating && (
                    <ReactStars className="awpa-pro-rating-review "
                        key={Math.random() * 100000}
                        count={post_rating_type}
                        value={(attributes.length > 0 ? attributes[0].avg : 0)}
                        size={star_sizes}
                        half={true}
                        edit={false}
                        color1={rating_color_back}
                        color2={rating_color_front}
                    />
                )}
                <span className='rating-avg'>{show_avg ? avg : ''}</span>
                {/* <span className='rating-type'>{show_star_type ? post_rating_type : ''} </span> */}
                <span className='rating-votes'> {show_votes ? count + ' reviews' : ''}</span>



            </AlertProvider >, element)
        }



    }
});