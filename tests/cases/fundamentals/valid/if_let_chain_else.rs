// exit: 0
// stdout: third
fn main() {
    let x: Option<i32> = Option::<i32>::None;
    let y: Option<i32> = Option::<i32>::None;
    let z: Option<i32> = Option::<i32>::Some(1);
    if let Some(_) = x {
        println!("first");
    } else if let Some(_) = y {
        println!("second");
    } else if let Some(_) = z {
        println!("third");
    } else {
        println!("none");
    }
}
