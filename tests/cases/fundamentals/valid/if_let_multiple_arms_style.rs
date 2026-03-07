// exit: 0
// stdout: a
// stdout: b
fn main() {
    let a: Option<i32> = Option::<i32>::Some(1);
    let b: Option<i32> = Option::<i32>::Some(2);
    if let Some(_) = a {
        println!("a");
    }
    if let Some(_) = b {
        println!("b");
    }
}
