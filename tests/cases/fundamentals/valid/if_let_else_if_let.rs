// exit: 0
// stdout: first
// stdout: second
fn main() {
    let a: Option<i32> = Option::<i32>::Some(1);
    let b: Option<i32> = Option::<i32>::None;
    if let Some(_) = a {
        println!("first");
    } else if let Some(_) = b {
        println!("second");
    } else {
        println!("none");
    }

    let c: Option<i32> = Option::<i32>::Some(2);
    if let Some(_) = b {
        println!("skip");
    } else if let Some(_) = c {
        println!("second");
    } else {
        println!("none");
    }
}
