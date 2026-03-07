// exit: 0
// stdout: one
// stdout: other

fn main() {
    let x: Option<i32> = Option::Some(1);
    if let Option::Some(1) = x {
        println!("one");
    } else {
        println!("other");
    }
    let y: Option<i32> = Option::Some(2);
    if let Option::Some(1) = y {
        println!("one");
    } else {
        println!("other");
    }
}
